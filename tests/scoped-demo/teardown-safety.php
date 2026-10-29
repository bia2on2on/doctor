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
 * Drop this process's option cache after ANOTHER process (the HTTP request)
 * wrote to the database.
 *
 * `get_option()` is cached per request. The drill reads the ownership ledger
 * before it drives the write, so without this the next read would be served
 * from that cache and the ledger would look untouched - which is exactly how
 * R1 first presented itself.
 *
 * @return void
 */
function cpsd_ts_refresh(): void {
	wp_cache_delete( ScopedOwnership::OPTION, 'options' );
	wp_cache_delete( ScopedOwnership::SEAL_OPTION, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/**
 * One HTTP round-trip against THIS site.
 *
 * The recorder hooks the WordPress request lifecycle (`init` -> `wp_loaded` ->
 * dispatch -> `shutdown`). A WP-CLI process is not that lifecycle: `init` runs
 * with no REQUEST_METHOD, so the guard stands down before a test could ever
 * call it. Driving the real lifecycle over HTTP is the only honest way to ask
 * "does a demo write get registered?".
 *
 * @param string              $url     Absolute URL.
 * @param array<string,string> $headers Request headers.
 * @param array|string|null   $body    Body.
 * @return array{status:int,body:string,error:string}
 */
function cpsd_ts_http( string $url, array $headers, $body = null ): array {
	$args = array(
		'method'      => 'POST',
		'timeout'     => 45,
		'redirection' => 0,
		'headers'     => $headers,
	);
	if ( null !== $body ) {
		$args['body'] = $body;
	}
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return array( 'status' => 0, 'body' => '', 'error' => $response->get_error_message() );
	}

	return array(
		'status' => (int) wp_remote_retrieve_response_code( $response ),
		'body'   => (string) wp_remote_retrieve_body( $response ),
		'error'  => '',
	);
}

/**
 * Authenticate ONE outbound request as a real WordPress user.
 *
 * A genuine session token plus the REST nonce that belongs to that exact
 * session - the same pair a browser would hold. Nothing is bypassed: the
 * far side authenticates the cookie itself.
 *
 * @param int $user_id User.
 * @return array{token:string,headers:array<string,string>}
 */
function cpsd_ts_auth( int $user_id ): array {
	$tokens = WP_Session_Tokens::get_instance( $user_id );
	$token  = (string) $tokens->create( time() + HOUR_IN_SECONDS );
	$cookie = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in', $token );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- the cookie is generated here, not read from the request.
	$backup = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? (string) $_COOKIE[ LOGGED_IN_COOKIE ] : null;
	$actor  = get_current_user_id();
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- see above.
	wp_set_current_user( $user_id );
	$nonce = (string) wp_create_nonce( 'wp_rest' );
	wp_set_current_user( $actor );
	if ( null === $backup ) {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	} else {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $backup; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- restoring the process's own state.
	}

	return array(
		'token'   => $token,
		'headers' => array(
			'Cookie'     => LOGGED_IN_COOKIE . '=' . $cookie,
			'X-WP-Nonce' => $nonce,
		),
	);
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
	// `cpms_visits.clinician_id` is NOT NULL with a foreign key to
	// `cpms_clinicians`, so a foreign visit needs a real foreign clinician. A
	// NULL here is rejected by the database, not by the product, and an insert
	// failure would make every later assertion meaningless.
	$ids['clinician'] = cpsd_ts_insert(
		$t( 'cpms_clinicians' ),
		array(
			'clinic_id'  => $ids['clinic'],
			'wp_user_id' => $overlap_user,
			'full_name'  => 'پزشک غیردمو',
			'specialty'  => 'general',
			'room'       => 'F1',
			'is_active'  => 1,
			'created_at' => $now,
			'updated_at' => $now,
		)
	);
	$ids['patient'] = cpsd_ts_insert(
		$t( 'cpms_patients' ),
		array( 'clinic_id' => $ids['clinic'], 'mrn' => $slug . '-mrn', 'first_name' => 'بیمار', 'last_name' => 'غیردمو', 'mobile' => '0936' . (string) random_int( 1000000, 9999999 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	// Migration 0013 made `cpms_visits.location_id` NOT NULL with a foreign key to
	// `cpms_locations`, so the visit must name the foreign Location this fixture
	// created. Without it the insert is rejected by the database (fk_visit_location)
	// and the fixture would be invalid rather than adversarial.
	$ids['visit'] = cpsd_ts_insert(
		$t( 'cpms_visits' ),
		array( 'clinic_id' => $ids['clinic'], 'location_id' => $ids['location'], 'clinician_id' => $ids['clinician'], 'patient_id' => $ids['patient'], 'source' => 'walk_in', 'status' => 'checked_in', 'visit_date' => gmdate( 'Y-m-d' ), 'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now )
	);
	// `cpms_invoices.visit_id` is NOT NULL and NOT NULL-declared for
	// `issued_by_wp_user_id` as well: a foreign invoice is only a valid fixture
	// when it names the foreign visit and a real issuer.
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

	// A fixture that did not really take the shape it claims is not evidence, so
	// every relationship the attacks depend on is re-read from the database.
	$visit = cpsd_ts_row( $t( 'cpms_visits' ), $ids['visit'] );
	$invoice = cpsd_ts_row( $t( 'cpms_invoices' ), $ids['invoice'] );
	if ( ! is_array( $visit ) || (int) $visit['clinician_id'] !== (int) $ids['clinician'] || (int) $visit['location_id'] !== (int) $ids['location'] ) {
		throw new RuntimeException( 'foreign fixture: the visit does not name its foreign clinician and Location' );
	}
	if ( ! is_array( $invoice ) || (int) $invoice['visit_id'] !== (int) $ids['visit'] || (int) $invoice['issued_by_wp_user_id'] !== (int) $overlap_user ) {
		throw new RuntimeException( 'foreign fixture: the invoice does not name its foreign visit and issuer' );
	}

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
		cpsd_ts_check( $intact >= 9, 'C: the whole planted foreign tenant survived the teardown', $checks, 'rows=' . $intact );
		// The demo's own data is gone, and the tables are still there (J).
		$demo_clinic = (int) ( $recorded['demo_clinic'] ?? 0 );
		cpsd_ts_check( $demo_clinic > 0, 'J: the drill recorded the demo Clinic', $checks );
		$demo_anchor = cpsd_ts_row( $t( 'cpms_clinics' ), $demo_clinic );
		cpsd_ts_check( is_array( $demo_anchor ), 'J: the demo Clinic anchor is RETAINED (never dropped) after the authorized teardown', $checks, (string) $demo_clinic );
		cpsd_ts_check( 'active' !== (string) ( $demo_anchor['status'] ?? '' ), 'J: the retained demo Clinic anchor is inert, not active', $checks, (string) ( $demo_anchor['status'] ?? '' ) );
		$demo_left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $t( 'cpms_patients' ) . '` WHERE clinic_id = ' . $demo_clinic ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table; integer literal.
		cpsd_ts_check( 0 === $demo_left, 'J: the demo Clinic holds no leftover patient rows', $checks, (string) $demo_left );
		// A/J: the patient the demo created over real HTTP is gone with the
		// fixture - the recorder's registration made it deletable, and the
		// teardown used exactly that.
		$registered_patient = (int) ( $recorded['registered_patient'] ?? 0 );
		if ( $registered_patient > 0 ) {
			cpsd_ts_check( null === cpsd_ts_row( $t( 'cpms_patients' ), $registered_patient ), 'A/J: the patient registered by the real REST write was deleted', $checks, (string) $registered_patient );
		}
		// F/J: the additional demo Clinic the drill created is inert, and holds
		// no leftover rows.
		$second_clinic = (int) ( $recorded['second_clinic'] ?? 0 );
		if ( $second_clinic > 0 ) {
			$second_row = cpsd_ts_row( $t( 'cpms_clinics' ), $second_clinic );
			cpsd_ts_check( is_array( $second_row ) && 'active' !== (string) ( $second_row['status'] ?? '' ), 'F/J: the additional demo Clinic is preserved and inert', $checks, (string) ( $second_row['status'] ?? 'gone' ) );
			$second_left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $t( 'cpms_patients' ) . '` WHERE clinic_id = ' . $second_clinic ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table; integer literal.
			cpsd_ts_check( 0 === $second_left, 'F/J: the additional demo Clinic holds no leftover patient rows', $checks, (string) $second_left );
		}
		// Real people the drill created (and the host administrator) are not the
		// demo's to delete.
		foreach ( array( 'host_user', 'real_admin', 'surface_user', 'foreign_user' ) as $key ) {
			$uid = (int) ( $recorded[ $key ] ?? 0 );
			if ( $uid > 0 ) {
				cpsd_ts_check( null !== get_userdata( $uid ), 'J: the real account survives the teardown (' . $key . ')', $checks, (string) $uid );
			}
		}
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
	// Over real HTTP, through the real lifecycle (cookie -> `wp_loaded` ->
	// REST dispatch -> `shutdown`), so the recorder is exercised exactly as it
	// is on the Owner's site.
	$mobile = '0937' . (string) random_int( 1000000, 9999999 );
	// Clear the recorder's own error slot first, so whatever is in it after the
	// write belongs to THIS request.
	delete_option( 'cpsd_recorder_error' );
	$auth = cpsd_ts_auth( $reception );
	$rest   = cpsd_ts_http(
		rest_url( '/clinic/v1/patients' ),
		array_merge(
			$auth['headers'],
			array(
				'X-CPMS-Clinic-Id' => (string) $demo_clinic,
				'Content-Type'     => 'application/json',
			)
		),
		(string) wp_json_encode(
			array(
				'first_name' => 'دوم',
				'last_name'  => 'بیمار نمایشی',
				'mobile'     => $mobile,
			)
		)
	);
	cpsd_ts_check(
		in_array( $rest['status'], array( 200, 201 ), true ),
		'A: the synthetic receptionist created a patient over real HTTP',
		$checks,
		'HTTP ' . $rest['status'] . ' ' . mb_substr( $rest['body'], 0, 200 ) . ' ' . $rest['error']
	);
	cpsd_ts_refresh();
	$payload     = json_decode( $rest['body'], true );
	$payload     = is_array( $payload ) ? $payload : array();
	$data        = (array) ( $payload['data'] ?? $payload );
	$patient_row = (array) ( $data['patient'] ?? $data );
	$new_patient = (int) ( $patient_row['id'] ?? 0 );
	cpsd_ts_check( $new_patient > 0, 'A: the real REST response names the new patient', $checks, mb_substr( (string) wp_json_encode( $payload ), 0, 200 ) );
	$created = cpsd_ts_row( $t( 'cpms_patients' ), $new_patient );
	cpsd_ts_check( is_array( $created ) && (string) ( $created['mobile'] ?? '' ) === $mobile, 'A: the new patient exists with a DIFFERENT mobile', $checks, $mobile );
	// The shared identity row the production service creates alongside the
	// patient has no `clinic_id`; it is organization-scoped. Both rows must be
	// registered, or the teardown cannot attribute either of them.
	$new_identity = is_array( $created ) ? (int) ( $created['identity_id'] ?? 0 ) : 0;
	if ( $new_identity > 0 ) {
		cpsd_ts_check( null !== cpsd_ts_row( $t( 'cpms_patient_identities' ), $new_identity ), 'A: the production service also created the shared identity row', $checks, 'identity=' . $new_identity );
	}
	$after_write = $teardown->preview( $manifest->load() );
	$recorder_error = (string) wp_json_encode( get_option( 'cpsd_recorder_error', array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	// What the guard saw INSIDE the process that served the write (the job
	// installs a test-only mu-plugin that records it at shutdown).
	$probe_tail = '';
	$probe_file = (string) ( getenv( 'CPSD_TS_PROBE' ) ?: '' );
	if ( '' !== $probe_file && is_file( $probe_file ) ) {
		$probe_lines = array_values( array_filter( array_map( 'trim', (array) file( $probe_file ) ) ) );
		$probe_tail  = (string) implode( ' || ', array_slice( $probe_lines, -2 ) );
	}
	$ledger_summary = $ownership->summary( (string) $live['fixture_id'] );
	$ledger_rows    = array(
		'patients'   => array_keys( (array) ( $ledger_summary['row_keys']['cpms_patients'] ?? array() ) ),
		'identities' => array_keys( (array) ( $ledger_summary['row_keys']['cpms_patient_identities'] ?? array() ) ),
	);
	cpsd_ts_check(
		true === (bool) $after_write['ok'],
		'A: the recorder REGISTERED every row that request created',
		$checks,
		implode( ' | ', array_slice( (array) ( $after_write['problems'] ?? array() ), 0, 3 ) )
			. ' recorder_error=' . mb_substr( $recorder_error, 0, 300 )
			. ' ledger=' . (string) wp_json_encode( $ledger_rows )
			. ' ledger_ok=' . ( (bool) ( $ledger_summary['ok'] ?? false ) ? '1' : '0' )
			. ' probe=' . mb_substr( $probe_tail, 0, 600 )
	);
	cpsd_ts_check( cpsd_ts_claimed( $after_write, 'cpms_patients', $new_patient ), 'A: the registered patient is inside the attributed scope', $checks, 'patient=' . $new_patient );
	$summary = $ownership->summary( (string) $live['fixture_id'] );
	cpsd_ts_check( true === (bool) ( $summary['ok'] ?? false ), 'A: the ownership ledger still verifies after the recorded write', $checks, (string) ( $summary['error'] ?? '' ) );
	WP_Session_Tokens::get_instance( $reception )->destroy( $auth['token'] );
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

	// ---- B2: a REGISTERED grant whose principal is a real person -------------
	// The exact defect this mission is about: registration is evidence that the
	// demo wrote a row, never evidence about WHO holds a grant. This row is
	// inserted and then explicitly sealed into the ownership ledger, so the old
	// implementation would have called it demo-owned and deleted a real person's
	// membership. It must still be refused.
	$b2_id = cpsd_ts_insert(
		$membership_table,
		array(
			'clinic_id'  => $demo_clinic,
			'wp_user_id' => $real_admin,
			'role_key'   => $role_key,
			'scope_mode' => 'clinic',
			'status'     => 'active',
			'is_primary' => 0,
			'created_at' => gmdate( 'Y-m-d H:i:s' ) . '.000',
			'updated_at' => gmdate( 'Y-m-d H:i:s' ) . '.000',
		)
	);
	cpsd_ts_check(
		null !== cpsd_ts_row( $membership_table, $b2_id ) && (int) ( cpsd_ts_row( $membership_table, $b2_id )['wp_user_id'] ?? 0 ) === $real_admin,
		'B2: the real administrator\'s membership really exists in the demo Clinic',
		$checks,
		'id=' . $b2_id
	);
	$sealed_b2 = $ownership->register_rows(
		(string) $live['fixture_id'],
		'cpms_clinic_memberships',
		array( $b2_id ),
		array( 'source' => 'drill', 'actor_id' => $manager )
	);
	cpsd_ts_check( null !== $sealed_b2, 'B2: the grant is now REGISTERED in the sealed ledger', $checks, (string) wp_json_encode( $sealed_b2 ) );
	cpsd_ts_refresh();
	$b2_summary = $ownership->summary( (string) $live['fixture_id'] );
	cpsd_ts_check(
		isset( $b2_summary['row_keys']['cpms_clinic_memberships'][ $b2_id ] ),
		'B2: the ledger really lists the grant (the registration took effect)',
		$checks,
		(string) wp_json_encode( array_keys( (array) ( $b2_summary['row_keys']['cpms_clinic_memberships'] ?? array() ) ) )
	);
	$b2_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check(
		false === (bool) $b2_preview['ok'],
		'B2: a REGISTERED grant to a real person is still REFUSED',
		$checks,
		implode( ' | ', array_slice( (array) ( $b2_preview['problems'] ?? array() ), 0, 3 ) )
	);
	cpsd_ts_check(
		cpsd_ts_problem( $b2_preview, 'cpms_clinic_memberships:' . $b2_id ),
		'B2: the refusal names the real person\'s registered grant',
		$checks,
		implode( ' | ', array_slice( (array) ( $b2_preview['problems'] ?? array() ), 0, 3 ) )
	);
	// The invariant is NOT "absent from the tenant scope": a membership row that
	// sits inside the demo Clinic legitimately belongs to the candidate set that
	// the scope reports, and an earlier revision of this drill asserted exactly
	// that and was therefore unable to pass no matter how correct the product
	// was. The invariant is what the PRODUCT actually does: the real teardown
	// path refuses, names this row, and deletes nothing at all.
	cpsd_ts_check(
		cpsd_ts_problem( $b2_preview, 'SCOPED_FOREIGN_TENANT_ROW:cpms_clinic_memberships:' . $b2_id ),
		'B2: the refusal names the exact registered grant row',
		$checks,
		implode( ' | ', array_slice( (array) ( $b2_preview['problems'] ?? array() ), 0, 3 ) )
	);
	$b2_actor    = (int) ( $live['ids']['admin_id'] ?? 0 );
	$b2_executed = null;
	$b2_code     = '';
	try {
		$b2_executed = $teardown->execute( $manifest->load(), $b2_actor > 0 ? $b2_actor : 1 );
	} catch ( \Throwable $error ) {
		$b2_code = $error instanceof CpmsDemo\Exception ? $error->demo_code() : (string) $error->getMessage();
	}
	cpsd_ts_check(
		null === $b2_executed && 'SCOPED_FOREIGN_TENANT_ROW' === $b2_code,
		'B2: the REAL teardown path REFUSES a registered grant to a real person',
		$checks,
		'code=' . $b2_code . ' | ' . implode( ' | ', array_slice( (array) ( $b2_preview['problems'] ?? array() ), 0, 3 ) )
	);
	cpsd_ts_check( null !== cpsd_ts_row( $membership_table, $b2_id ), 'B2: the refused teardown deleted nothing', $checks, 'id=' . $b2_id );
	cpsd_ts_check( null !== get_userdata( $real_admin ), 'B2: the real person still exists after the refused teardown', $checks, (string) $real_admin );
	$wpdb->delete( $membership_table, array( 'id' => $b2_id ), array( '%d' ) );
	cpsd_ts_refresh();
	cpsd_ts_check( true === (bool) $teardown->preview( $manifest->load() )['ok'], 'B2: removing the registered real grant restores a clean preview', $checks );

	// ---- C: a foreign tenant that overlaps the demo's own Organization ------
	// Its actor references are a REAL user the drill creates: a foreign tenant
	// that legitimately belongs to a real person. (A foreign row that points at
	// a SYNTHETIC account is a different case - it must BLOCK the teardown,
	// which is scenario C2 below.)
	$foreign_user = (int) wp_insert_user(
		array(
			'user_login'   => 'ts-foreign-' . strtolower( bin2hex( random_bytes( 3 ) ) ),
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => 'ts-foreign-' . strtolower( bin2hex( random_bytes( 3 ) ) ) . '@example.test',
			'display_name' => 'real foreign staff',
			'role'         => 'subscriber',
		)
	);
	cpsd_ts_check( $foreign_user > 0, 'C: a real user for the foreign tenant was created', $checks );
	$foreign = cpsd_ts_plant_foreign( $platform, $t, $demo_org, $foreign_user, $role_key );
	$report['foreign_user'] = $foreign_user;
	foreach ( $foreign as $key => $id ) {
		cpsd_ts_check( $id > 0, 'C: the foreign ' . $key . ' was really created', $checks, (string) $id );
	}
	$c_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check( true === (bool) $c_preview['ok'], 'C: a foreign tenant does not block the demo teardown', $checks, implode( ' | ', (array) ( $c_preview['problems'] ?? array() ) ) );
	foreach ( array( 'organization', 'clinic', 'location', 'clinician', 'patient', 'visit', 'invoice', 'payment', 'membership' ) as $key ) {
		$short = array(
			'organization' => 'cpms_organizations',
			'clinic'     => 'cpms_clinics',
			'location'   => 'cpms_locations',
			'clinician'  => 'cpms_clinicians',
			'patient'    => 'cpms_patients',
			'visit'      => 'cpms_visits',
			'invoice'    => 'cpms_invoices',
			'payment'    => 'cpms_payments',
			'membership' => 'cpms_clinic_memberships',
		)[ $key ];
		cpsd_ts_check( ! cpsd_ts_claimed( $c_preview, $short, (int) $foreign[ $key ] ), 'C: the foreign ' . $key . ' is never claimed', $checks, $short . '#' . $foreign[ $key ] );
		$planted[ 'foreign_' . $key ] = array( 'table' => $t( $short ), 'id' => (int) $foreign[ $key ], 'hash' => cpsd_ts_hash( $t( $short ), (int) $foreign[ $key ] ) );
	}

	// ---- C2: a foreign row that depends on a SYNTHETIC account -------------
	// The teardown deletes synthetic accounts, so a FOREIGN tenant row that
	// still references one would lose the user underneath it. That must refuse
	// (SCOPED_FOREIGN_DEPENDENCY) rather than silently break the foreign tenant.
	$c2_membership = cpsd_ts_insert(
		$t( 'cpms_clinic_memberships' ),
		array(
			'clinic_id'  => (int) $foreign['clinic'],
			'wp_user_id' => $reception,
			'role_key'   => $role_key,
			'scope_mode' => 'clinic',
			'status'     => 'active',
			'is_primary' => 0,
			'created_at' => gmdate( 'Y-m-d H:i:s' ) . '.000',
			'updated_at' => gmdate( 'Y-m-d H:i:s' ) . '.000',
		)
	);
	cpsd_ts_check( null !== cpsd_ts_row( $t( 'cpms_clinic_memberships' ), $c2_membership ), 'C2: the foreign row that depends on a synthetic account really exists', $checks, 'id=' . $c2_membership );
	$c2_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check( false === (bool) $c2_preview['ok'], 'C2: a foreign dependency on a synthetic account blocks the teardown', $checks, implode( ' | ', array_slice( (array) ( $c2_preview['problems'] ?? array() ), 0, 3 ) ) );
	cpsd_ts_check( cpsd_ts_problem( $c2_preview, 'SCOPED_FOREIGN_DEPENDENCY' ), 'C2: the refusal is the foreign-dependency code', $checks, implode( ' | ', (array) ( $c2_preview['problems'] ?? array() ) ) );
	cpsd_ts_check( null !== cpsd_ts_row( $t( 'cpms_clinic_memberships' ), $c2_membership ), 'C2: the refused preview deleted the foreign row (it must not)', $checks );
	$wpdb->delete( $t( 'cpms_clinic_memberships' ), array( 'id' => $c2_membership ), array( '%d' ) );
	cpsd_ts_check( true === (bool) $teardown->preview( $manifest->load() )['ok'], 'C2: removing the dependency restores a clean preview', $checks );

	// ---- K: every write surface refuses a REAL actor ------------------------
	// The barrier runs at `wp_loaded`, i.e. before admin-post, admin-AJAX, the
	// Management Portal's template handler and REST dispatch. This drives all
	// four surfaces over HTTP with a real, non-fixture, authenticated user.
	$surface_user = (int) wp_insert_user(
		array(
			'user_login'   => 'ts-surface-' . strtolower( bin2hex( random_bytes( 3 ) ) ),
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => 'ts-surface-' . strtolower( bin2hex( random_bytes( 3 ) ) ) . '@example.test',
			'display_name' => 'real surface probe',
			'role'         => 'subscriber',
		)
	);
	cpsd_ts_check( $surface_user > 0, 'K: a real (non-fixture) user was created for the surface probe', $checks );
	$surface_auth = cpsd_ts_auth( $surface_user );
	$management   = (string) ( $live['links']['management'] ?? home_url( '/' ) );

	$admin_post = cpsd_ts_http(
		home_url( '/wp-admin/admin-post.php' ),
		$surface_auth['headers'],
		array( 'action' => 'cpsd_surface_probe', 'clinic_id' => $demo_clinic )
	);
	cpsd_ts_check(
		403 === $admin_post['status'] && false !== strpos( $admin_post['body'], 'دموی محدودشده' ),
		'K: admin-post refuses a real actor writing into the demo Clinic',
		$checks,
		'HTTP ' . $admin_post['status'] . ' ' . mb_substr( $admin_post['body'], 0, 160 ) . ' ' . $admin_post['error']
	);

	$ajax = cpsd_ts_http(
		home_url( '/wp-admin/admin-ajax.php?action=cpsd_surface_probe' ),
		$surface_auth['headers'],
		array( 'clinic_id' => $demo_clinic )
	);
	cpsd_ts_check(
		403 === $ajax['status'] && false !== strpos( $ajax['body'], 'دموی محدودشده' ),
		'K: admin-AJAX refuses the same write',
		$checks,
		'HTTP ' . $ajax['status'] . ' ' . mb_substr( $ajax['body'], 0, 160 )
	);

	$portal_post = cpsd_ts_http(
		$management,
		$surface_auth['headers'],
		array( 'clinic_id' => $demo_clinic, 'cpms_module' => 'management' )
	);
	cpsd_ts_check(
		403 === $portal_post['status'],
		'K: the Management Portal refuses the same write',
		$checks,
		'HTTP ' . $portal_post['status'] . ' ' . mb_substr( $portal_post['body'], 0, 160 )
	);

	$rest_refused = cpsd_ts_http(
		rest_url( '/clinic/v1/patients' ),
		array_merge(
			$surface_auth['headers'],
			array(
				'X-CPMS-Clinic-Id' => (string) $demo_clinic,
				'Content-Type'     => 'application/json',
			)
		),
		(string) wp_json_encode(
			array(
				'first_name' => 'نفوذی',
				'last_name'  => 'واقعی',
				'mobile'     => '0936' . (string) random_int( 1000000, 9999999 ),
			)
		)
	);
	cpsd_ts_check(
		403 === $rest_refused['status'],
		'K: REST refuses the real actor writing into the demo Clinic',
		$checks,
		'HTTP ' . $rest_refused['status'] . ' ' . mb_substr( $rest_refused['body'], 0, 160 )
	);

	// Negative control: the SAME actor and the SAME surfaces stay usable for a
	// FOREIGN tenant. A barrier that blocks everything is not a fix.
	$foreign_ajax = cpsd_ts_http(
		home_url( '/wp-admin/admin-ajax.php?action=cpsd_surface_probe' ),
		$surface_auth['headers'],
		array( 'clinic_id' => (int) $foreign['clinic'] )
	);
	cpsd_ts_check(
		403 !== $foreign_ajax['status'],
		'K: a foreign tenant stays writable for the same real actor',
		$checks,
		'HTTP ' . $foreign_ajax['status'] . ' ' . mb_substr( $foreign_ajax['body'], 0, 120 )
	);
	$foreign_patients_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $t( 'cpms_patients' ) . '` WHERE clinic_id = ' . (int) $foreign['clinic'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table; integer literal.
	cpsd_ts_check( $foreign_patients_after >= 1, 'K: the foreign tenant still has its own rows after the probes', $checks, (string) $foreign_patients_after );
	WP_Session_Tokens::get_instance( $surface_user )->destroy( $surface_auth['token'] );
	cpsd_ts_refresh();
	$report['surface_user'] = $surface_user;

	// ---- D: a cross-table numeric collision grants nothing -----------------
	// The attack: an invoice whose OWN id equals a SEALED id from a DIFFERENT
	// table. If ownership were ever resolved by "this number is sealed
	// somewhere", that invoice would be claimed. It lives in the
	// FOREIGN tenant with its own valid patient/visit/issuer, so the row is a
	// legal record that the demo must simply never touch.
	// The collision needs an id that is sealed by the demo for a table that is
	// NOT the invoice table AND is still free in `cpms_invoices`, because the
	// foreign invoice has to TAKE that id for the attack to exist at all.
	//
	// Choosing the demo patient id unconditionally made the drill depend on a
	// coincidence: on a run where the demo's own invoice already held that id the
	// precondition failed and no attack was ever mounted - a fixture bug, not a
	// product finding. Every sealed id from another table is therefore
	// considered, deterministically in manifest order.
	$invoice_table = $t( 'cpms_invoices' );
	$sealed_map    = array(
		'location_ids'  => 'cpms_locations',
		'clinician_id'  => 'cpms_clinicians',
		'patient_id'    => 'cpms_patients',
		'identity_id'   => 'cpms_patient_identities',
		'visit_id'      => 'cpms_visits',
		'appointment_id' => 'cpms_appointments',
		'payment_id'    => 'cpms_payments',
		'note_ids'      => 'cpms_clinical_notes',
		'prescription_id' => 'cpms_prescriptions',
		'follow_up_id'  => 'cpms_follow_ups',
	);
	$d_candidates = array();
	foreach ( $sealed_map as $sealed_key => $sealed_table_name ) {
		foreach ( (array) ( $live['ids'][ $sealed_key ] ?? array() ) as $sealed_value ) {
			$candidate = (int) $sealed_value;
			if ( $candidate <= 0 || null !== cpsd_ts_row( $invoice_table, $candidate ) ) {
				continue;
			}
			if ( null === cpsd_ts_row( $t( $sealed_table_name ), $candidate ) ) {
				// The manifest names it but the row is gone: not a collision.
				continue;
			}
			$d_candidates[] = array( 'key' => $sealed_key, 'table' => $sealed_table_name, 'id' => $candidate );
		}
	}
	cpsd_ts_check(
		array() !== $d_candidates,
		'D: a sealed id from another table is FREE in cpms_invoices (the collision can be mounted)',
		$checks,
		(string) wp_json_encode( array_slice( $d_candidates, 0, 5 ) )
	);
	if ( array() === $d_candidates ) {
		throw new RuntimeException( 'D: no free sealed id exists in cpms_invoices; the numeric-collision attack cannot be mounted' );
	}
	$d_sealed        = $d_candidates[0];
	$sealed_patient  = (int) $d_sealed['id'];
	$d_sealed_row    = cpsd_ts_row( $t( (string) $d_sealed['table'] ), $sealed_patient );
	cpsd_ts_check(
		is_array( $d_sealed_row ),
		'D: that id really exists in the table the demo sealed (a genuine cross-table collision)',
		$checks,
		$d_sealed['key'] . '=' . $sealed_patient . ' in ' . $d_sealed['table']
	);
	cpsd_ts_check( null === cpsd_ts_row( $invoice_table, $sealed_patient ), 'D: the collision id is FREE in cpms_invoices', $checks, 'id=' . $sealed_patient );
	$now_d = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$d_id  = cpsd_ts_insert(
		$invoice_table,
		array(
			'id'                    => $sealed_patient,
			'clinic_id'             => (int) $foreign['clinic'],
			'invoice_number'        => 'TSCOL' . strtoupper( bin2hex( random_bytes( 3 ) ) ),
			'patient_id'            => (int) $foreign['patient'],
			'visit_id'              => (int) $foreign['visit'],
			'status'                => 'open',
			'subtotal'              => 1,
			'discount'              => 0,
			'tax'                   => 0,
			'total'                 => 1,
			'currency'              => 'IRR',
			'paid_amount'           => 0,
			'balance'               => 1,
			'issued_by_wp_user_id'  => $foreign_user,
			'created_at'            => $now_d,
			'updated_at'            => $now_d,
		)
	);
	cpsd_ts_check( $d_id === $sealed_patient, 'D: the colliding invoice really took an id the demo sealed elsewhere', $checks, 'invoice=' . $d_id );
	$d_row = cpsd_ts_row( $invoice_table, $d_id );
	cpsd_ts_check(
		is_array( $d_row )
			&& (int) $d_row['id'] === $sealed_patient
			&& is_array( $d_sealed_row ),
		'D: the attack condition holds - one id, two tables, one sealed by the demo',
		$checks,
		'invoice=' . $d_id . ' sealed=' . $d_sealed['key'] . ':' . $d_sealed['table']
	);
	$d_preview = $teardown->preview( $manifest->load() );
	cpsd_ts_check( ! cpsd_ts_claimed( $d_preview, 'cpms_invoices', $d_id ), 'D: the numeric collision does not make the invoice demo-owned', $checks, 'invoice=' . $d_id );
	cpsd_ts_check( true === (bool) $d_preview['ok'], 'D: a foreign tenant with a colliding id does not block the teardown', $checks, implode( ' | ', array_slice( (array) ( $d_preview['problems'] ?? array() ), 0, 3 ) ) );
	$planted['foreign_collision_invoice'] = array( 'table' => $invoice_table, 'id' => $d_id, 'hash' => cpsd_ts_hash( $invoice_table, $d_id ) );

	// ---- E: unknown ownership blocks destructive work ----------------------
	$visit_table = $t( 'cpms_visits' );
	$now_e       = gmdate( 'Y-m-d H:i:s' ) . '.000';
	// The row sits INSIDE the demo Clinic, so it is a deletion candidate, but its
	// patient belongs to the foreign tenant: no artifact explains it, so it is
	// unprovable. `clinician_id` is NOT NULL, so it names the demo's own sealed
	// clinician - the visit is a legal record, it is only its OWNERSHIP that is
	// unknown.
	// A precondition, not a fixture: the sealed manifest always names the demo
	// clinician, and inventing one would leave a second unprovable row behind
	// and make the "removing the row restores a clean preview" check meaningless.
	$demo_clinician = (int) ( $live['ids']['clinician_id'] ?? 0 );
	cpsd_ts_check(
		$demo_clinician > 0 && null !== cpsd_ts_row( $t( 'cpms_clinicians' ), $demo_clinician ),
		'E: precondition - the sealed demo clinician exists',
		$checks,
		(string) $demo_clinician
	);
	// `cpms_visits.location_id` is NOT NULL (migration 0013, fk_visit_location), so
	// the demo-side visit names a Location the demo Clinic really owns.
	$demo_locations = array_map( 'intval', (array) ( $live['ids']['location_ids'] ?? array() ) );
	$demo_location  = $demo_locations[0] ?? 0;
	if ( $demo_location <= 0 || null === cpsd_ts_row( $t( 'cpms_locations' ), $demo_location ) ) {
		$demo_location = (int) $wpdb->get_var( 'SELECT id FROM `' . $t( 'cpms_locations' ) . '` WHERE clinic_id = ' . $demo_clinic . ' ORDER BY is_primary DESC, id ASC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table + integer literal.
	}
	cpsd_ts_check( $demo_location > 0, 'E: precondition - the demo Clinic has a Location for the visit', $checks, (string) $demo_location );
	$e_id        = cpsd_ts_insert(
		$visit_table,
		array( 'clinic_id' => $demo_clinic, 'location_id' => $demo_location, 'clinician_id' => $demo_clinician, 'patient_id' => (int) $foreign['patient'], 'source' => 'walk_in', 'status' => 'checked_in', 'visit_date' => gmdate( 'Y-m-d' ), 'check_in_at' => $now_e, 'created_at' => $now_e, 'updated_at' => $now_e )
	);
	$e_row = cpsd_ts_row( $visit_table, $e_id );
	cpsd_ts_check(
		is_array( $e_row ) && (int) $e_row['clinic_id'] === $demo_clinic && (int) $e_row['patient_id'] === (int) $foreign['patient'],
		'E: the unprovable visit inside the demo Clinic really exists',
		$checks,
		'id=' . $e_id
	);
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
