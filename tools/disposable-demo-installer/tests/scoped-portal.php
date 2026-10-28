<?php
/**
 * Real-WordPress management-portal lifecycle dr\ills for the scoped demo.
 *
 * Run inside the disposable CI WordPress after scoped-recovery.php:
 *   wp eval-file tools/disposable-demo-installer/tests/scoped-portal.php
 *
 * The reported defect this file pins down: a synthetic Organization manager
 * changes the demo Clinic's lifecycle status through the REAL management
 * portal. `OrganizationClinicProvisioningService::set_clinic_status()` stamps
 * `cpms_clinics.status_updated_by_wp_user_id` with that synthetic account and
 * appends a clinic audit row. The preserved Clinic is archived in place (it is
 * NOT part of the deletable row sets), so the teardown's live-only dependency
 * scan used to declare the demo anchor "foreign" and fail midway with
 * `SCOPED_FOREIGN_DEPENDENCY` / `teardown_failed`.
 *
 * Every scenario below runs against the real database and the real production
 * portal dispatch (never a mock):
 *
 *   P1  lifecycle toggle -> authorized teardown completes, anchors inert,
 *       audit chain intact, every pre-existing host row byte-identical
 *   P2  a second Clinic created through the portal under the demo Organization
 *       is provably demo-owned: it and its dependents are cleaned selectively,
 *       the Clinic/Location anchors are retained inert, no foreign row is
 *       claimed, and the teardown succeeds
 *   P3  a Clinic that names the demo Organization but predates the sealed
 *       baseline cannot be proven demo-owned: the preview and the execution
 *       both refuse with SCOPED_OWNERSHIP_UNPROVEN before any destructive
 *       stage, deleting nothing; after the row is removed the teardown
 *       completes normally
 *
 * @package CpmsDisposableDemo
 */

use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\ManagementPortalController;
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
 * @param bool   $ok Condition.
 * @param string $label Label.
 * @param list<string> $checks Accumulator.
 * @return void
 */
function cpsd_pt_check( bool $ok, string $label, array &$checks ): void {
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
function cpsd_pt_insert( string $table, array $data ): int {
	global $wpdb;
	$ok = $wpdb->insert( $table, $data );
	if ( false === $ok || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( 'insert failed: ' . $table . ' ' . (string) $wpdb->last_error );
	}

	return (int) $wpdb->insert_id;
}

/**
 * @param string $table Table.
 * @param int    $id Row id.
 * @return void
 */
function cpsd_pt_delete( string $table, int $id ): void {
	global $wpdb;
	$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
}

/**
 * @param Platform $platform Adapter.
 * @param string   $short Short table name.
 * @param string   $sql Prepared SQL.
 * @param list<int> $params Params.
 * @return mixed
 */
function cpsd_pt_scalar( string $sql, array $params = array() ) {
	global $wpdb;

	return $params === array()
		? $wpdb->get_var( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- caller supplies a fixed statement.
		: $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared here.
}

/**
 * Deterministic fingerprint of the pre-existing (host) Clinics and everything
 * hanging off them: a lifecycle stamp that leaked outside the fixture, or a
 * deleted host row, changes it.
 *
 * @param Platform         $platform Adapter.
 * @param list<int>        $clinics Host Clinic ids.
 * @return string
 */
function cpsd_pt_host_digest( Platform $platform, array $clinics ): string {
	global $wpdb;
	$rows = array();
	foreach ( array( 'cpms_clinics', 'cpms_locations', 'cpms_clinicians', 'cpms_patients', 'cpms_appointments', 'cpms_visits', 'cpms_invoices', 'cpms_payments', 'cpms_schedule_slots' ) as $short ) {
		$table = $platform->table( $short );
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM `' . $table . '` WHERE clinic_id IN (' . implode( ',', array_fill( 0, count( $clinics ), '%d' ) ) . ') ORDER BY id ASC', $clinics ) );
		foreach ( (array) $ids as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $table . '` WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from a fixed list; id prepared.
			$rows[] = $short . '#' . (int) $id . ':' . md5( (string) wp_json_encode( is_array( $row ) ? $row : array() ) );
		}
	}

	return md5( implode( '|', $rows ) );
}

/**
 * Row count of every row the fixture currently holds in one of its Clinics.
 *
 * @param Platform  $platform Adapter.
 * @param list<int> $clinics Clinic ids that belong to the fixture.
 * @return array<string,int>
 */
function cpsd_pt_fixture_counts( Platform $platform, array $clinics ): array {
	global $wpdb;
	$out = array();
	foreach ( array( 'cpms_locations', 'cpms_clinicians', 'cpms_patients', 'cpms_appointments', 'cpms_visits', 'cpms_invoices', 'cpms_payments', 'cpms_clinical_notes', 'cpms_schedule_slots' ) as $short ) {
		$table      = $platform->table( $short );
		$out[ $short ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE clinic_id IN (' . implode( ',', array_fill( 0, count( $clinics ), '%d' ) ) . ')', $clinics ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from a fixed list; values prepared.
	}
	$out['wp_synthetic_users'] = count( cpsd_pt_synthetic_users() );

	return $out;
}

/**
 * Every fixture-prefixed account that still exists.
 *
 * @return list<int>
 */
function cpsd_pt_synthetic_users(): array {
	global $wpdb;
	// ScopedManifest::fixture_prefix() is "cpsd-", so every synthetic login is
	// cpsd-<persona>-<32 hex>. No pre-existing site account can match it.
	$pattern = $wpdb->esc_like( 'cpsd-' ) . '%';

	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s ORDER BY ID ASC", $pattern ) ) );
}

$checks = array();
$report = array( 'P1' => array(), 'P2' => array(), 'P3' => array() );
try {
	if ( ! class_exists( App::class ) || ! class_exists( Platform::class ) ) {
		throw new RuntimeException( 'CPMS/companion not active' );
	}
	if ( ! class_exists( ManagementPortalController::class ) ) {
		throw new RuntimeException( 'management portal controller not loadable' );
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

	// ---------------------------------------------------------------- host seed
	// Two pre-existing Clinics (the scoped preflight refuses a single-Clinic
	// host). One of them is given an explicit high id so the sealed baseline
	// `max_id` leaves a gap: P3 needs a genuinely free id BELOW that max.
	$now   = gmdate( 'Y-m-d H:i:s' ) . '.000';
	$table = static fn ( string $short ): string => $platform->table( $short );
	$tag   = 'portal-' . bin2hex( random_bytes( 4 ) );

	$host_user = wp_insert_user( array( 'user_login' => $tag . '-host', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $tag . '-host@example.test', 'role' => 'subscriber' ) );
	if ( ! is_int( $host_user ) ) {
		throw new RuntimeException( 'host user seed failed' );
	}
	$host_org = cpsd_pt_insert( $table( 'cpms_organizations' ), array( 'name' => 'Host Org ' . $tag, 'slug' => $tag . '-org', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$host_clinic_a = cpsd_pt_insert( $table( 'cpms_clinics' ), array( 'organization_id' => $host_org, 'name' => 'Host Clinic A ' . $tag, 'slug' => $tag . '-a', 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$host_org_b    = cpsd_pt_insert( $table( 'cpms_organizations' ), array( 'name' => 'Host Org B ' . $tag, 'slug' => $tag . '-org-b', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$host_clinic_b = cpsd_pt_insert( $table( 'cpms_clinics' ), array( 'organization_id' => $host_org_b, 'name' => 'Host Clinic B ' . $tag, 'slug' => $tag . '-b', 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$host_clinician_a = cpsd_pt_insert( $table( 'cpms_clinicians' ), array( 'clinic_id' => $host_clinic_a, 'wp_user_id' => $host_user, 'full_name' => 'Host Clinician ' . $tag, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$host_patient_a   = cpsd_pt_insert( $table( 'cpms_patients' ), array( 'clinic_id' => $host_clinic_a, 'mrn' => $tag . '-mrn-a', 'first_name' => 'Host', 'last_name' => 'Patient A', 'mobile' => '0912000' . (string) random_int( 1000, 9999 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$host_location_a  = cpsd_pt_insert( $table( 'cpms_locations' ), array( 'clinic_id' => $host_clinic_a, 'name' => 'Host Location A', 'slug' => $tag . '-loc-a', 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$host_location_b  = cpsd_pt_insert( $table( 'cpms_locations' ), array( 'clinic_id' => $host_clinic_b, 'name' => 'Host Location B', 'slug' => $tag . '-loc-b', 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$host_patient_b   = cpsd_pt_insert( $table( 'cpms_patients' ), array( 'clinic_id' => $host_clinic_b, 'mrn' => $tag . '-mrn-b', 'first_name' => 'Host', 'last_name' => 'Patient B', 'mobile' => '0912000' . (string) random_int( 1000, 9999 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	// A pre-existing Clinic with an explicit high id creates free ids BELOW the
	// sealed baseline `max_id`. P3 needs exactly that: a Clinic row that names the
	// demo Organization but cannot be post-baseline.
	$host_org_c    = cpsd_pt_insert( $table( 'cpms_organizations' ), array( 'name' => 'Host Org C ' . $tag, 'slug' => $tag . '-org-c', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$gap_id        = 1000;
	cpsd_pt_check( null === cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $gap_id ) ), 'host id ' . $gap_id . ' is free before the gap Clinic is seeded', $checks );
	$wpdb->insert(
		$table( 'cpms_clinics' ),
		array( 'id' => $gap_id, 'organization_id' => $host_org_c, 'name' => 'Host Clinic C ' . $tag, 'slug' => $tag . '-c', 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	cpsd_pt_check( '' === (string) $wpdb->last_error, 'the pre-existing gap Clinic was seeded at id ' . $gap_id, $checks );
	cpsd_pt_check( $host_clinic_a > 1 && $host_clinic_b > 1 && $host_clinic_a !== $host_clinic_b, 'host has two pre-existing Clinics', $checks );
	cpsd_pt_check( 2 <= $platform->count_all( 'cpms_clinics' ), 'host has multi-Clinic semantics', $checks );

	$host_clinics      = array( $host_clinic_a, $host_clinic_b, $gap_id );
	$host_digest       = cpsd_pt_host_digest( $platform, $host_clinics );
	$report['host']    = array( 'clinics' => $host_clinics, 'digest' => substr( $host_digest, 0, 16 ) );

	// ================================================================ scenario P1
	// The reported defect: the synthetic Organization manager changes the demo
	// Clinic's lifecycle through the real portal, then the Administrator tears down.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live = $manifest->load();
	cpsd_pt_check( is_array( $live ) && 'active' === (string) $live['status'], 'P1 fixture provisioned', $checks );
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$demo_org    = (int) $live['ids']['organization_id'];
	$manager_id  = (int) $live['ids']['user_ids']['organization_manager'];
	cpsd_pt_check( $demo_clinic > 1 && $demo_org > 1 && $manager_id > 1, 'P1 demo ids and the synthetic Organization manager are known', $checks );

	// Evidence, not assertion: record the production capability decisions the
	// portal will make, so a refusal names the missing capability.
	$organizations_service = App::organization_clinic_provisioning_service();
	foreach ( array( 'cpms_org_clinic_create', 'cpms_org_clinic_profile_update', 'cpms_org_clinic_lifecycle' ) as $capability ) {
		$can = $organizations_service->can( $manager_id, $demo_org, $capability );
		$checks[] = 'INFO P1 capability ' . $capability . '=' . ( $can ? 'granted' : 'denied' ) . ' for the synthetic Organization manager';
	}
	$manager_clinics = $organizations_service->clinics_for_organization( $manager_id, $demo_org );
	$checks[] = 'INFO P1 clinics_for_organization=' . count( $manager_clinics );

	$deny = ManagementPortalController::dispatch( $host_user, 'org_clinic_status', array( 'organization_id' => $demo_org, 'clinic_id' => $demo_clinic, 'active' => false ) );
	cpsd_pt_check( false === (bool) $deny['ok'], 'P1 an unauthorized account cannot change the demo Clinic lifecycle (portal refused)', $checks );
	cpsd_pt_check( 'active' === (string) cpsd_pt_scalar( 'cpms_clinics', 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $demo_clinic ) ), 'P1 the refused attempt left the demo Clinic active', $checks );

	$deactivate = ManagementPortalController::dispatch( $manager_id, 'org_clinic_status', array( 'organization_id' => $demo_org, 'clinic_id' => $demo_clinic, 'active' => false ) );
	cpsd_pt_check( true === (bool) $deactivate['ok'], 'P1 the synthetic Organization manager deactivated the demo Clinic through the real portal', $checks );
	$stamped = cpsd_pt_scalar( 'cpms_clinics', 'SELECT status_updated_by_wp_user_id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $demo_clinic ) );
	cpsd_pt_check( (int) $stamped === $manager_id, 'P1 the lifecycle writer stamped status_updated_by_wp_user_id with the synthetic manager (' . (string) $stamped . ')', $checks );
	cpsd_pt_check( 'suspended' === (string) cpsd_pt_scalar( 'cpms_clinics', 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $demo_clinic ) ), 'P1 the demo Clinic is suspended after the portal action', $checks );
	$reactivate = ManagementPortalController::dispatch( $manager_id, 'org_clinic_status', array( 'organization_id' => $demo_org, 'clinic_id' => $demo_clinic, 'active' => true ) );
	cpsd_pt_check( true === (bool) $reactivate['ok'], 'P1 the synthetic Organization manager reactivated the demo Clinic', $checks );
	$report['P1']['stamped_by'] = $manager_id;

	$preview = $teardown->preview( $manifest->load() );
	cpsd_pt_check(
		true === (bool) $preview['ok'],
		'P1 teardown preview passes after a lifecycle change (' . implode( ' | ', (array) $preview['problems'] ) . ')',
		$checks
	);
	cpsd_pt_check(
		in_array( $demo_clinic, array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_clinics'] ?? array() ) ), true ),
		'P1 the preserved demo Clinic is classified demo-owned in the preview',
		$checks
	);
	$p1_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p1_result['ok'], 'P1 authorized teardown completes after the lifecycle change', $checks );
	cpsd_pt_check( null === $manifest->load(), 'P1 the manifest is released', $checks );
	cpsd_pt_check( 0 === count( cpsd_pt_synthetic_users() ), 'P1 no synthetic account survives', $checks );
	cpsd_pt_check(
		cpsd_pt_host_digest( $platform, $host_clinics ) === $host_digest,
		'P1 every pre-existing host row is byte-identical',
		$checks
	);
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P1 audit chain intact', $checks );
	cpsd_pt_check( $host_user > 0 && null !== get_userdata( $host_user ), 'P1 the pre-existing site account survives', $checks );
	$deleted_counts = array_filter( (array) $p1_result['deleted'], 'is_numeric' );
	$report['P1']['deleted'] = (int) array_sum( array_map( 'intval', $deleted_counts ) );

	// ================================================================ scenario P2
	// A second Clinic created through the real portal under the demo Organization.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live      = $manifest->load();
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$demo_org    = (int) $live['ids']['organization_id'];
	$manager_id  = (int) $live['ids']['user_ids']['organization_manager'];

	$created = ManagementPortalController::dispatch(
		$manager_id,
		'org_create_clinic',
		array(
			'organization_id' => $demo_org,
			'cpms_entity_name' => 'درمانگاه دوم دمو ' . $tag,
			'slug'             => $tag . '-second',
			'timezone'         => 'Asia/Tehran',
			'location_name'    => 'شعبه دوم دمو',
			'phone'            => '02100000000',
		)
	);
	cpsd_pt_check( true === (bool) $created['ok'], 'P2 the synthetic Organization manager created a second Clinic under the demo Organization', $checks );
	global $wpdb;
	$second_clinic = (int) cpsd_pt_scalar( 'cpms_clinics', 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE slug = %s', array( $tag . '-second' ) );
	cpsd_pt_check( $second_clinic > $demo_clinic, 'P2 the second Clinic is a distinct post-baseline row (' . $second_clinic . ')', $checks );
	cpsd_pt_check(
		(string) cpsd_pt_scalar( 'cpms_clinics', 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $second_clinic ) ) === 'active',
		'P2 the second Clinic starts active',
		$checks
	);

	// A dependent row inside the second Clinic, created by a synthetic account:
	// it must be cleaned selectively with the Clinic, never left as residue and
	// never mistaken for a foreign row.
	$second_patient = cpsd_pt_insert(
		$table( 'cpms_patients' ),
		array( 'clinic_id' => $second_clinic, 'mrn' => 'P2-' . strtoupper( bin2hex( random_bytes( 3 ) ) ), 'first_name' => 'دوم', 'last_name' => 'بیمار ' . $tag, 'mobile' => '0912' . (string) random_int( 1000000, 9999999 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);

	$preview = $teardown->preview( $manifest->load() );
	cpsd_pt_check( true === (bool) $preview['ok'], 'P2 teardown preview passes (' . implode( ' | ', (array) $preview['problems'] ) . ')', $checks );
	$anchors = array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_clinics'] ?? array() ) );
	cpsd_pt_check( in_array( $demo_clinic, $anchors, true ), 'P2 the original demo Clinic is a demo-owned anchor', $checks );
	cpsd_pt_check( in_array( $second_clinic, $anchors, true ), 'P2 the portal-created Clinic is provably demo-owned and claimed', $checks );
	cpsd_pt_check( ! in_array( $host_clinic_a, $anchors, true ) && ! in_array( $host_clinic_b, $anchors, true ), 'P2 no pre-existing Clinic is claimed', $checks );
	$second_locations = array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_locations'] ?? array() ) );
	cpsd_pt_check( ! in_array( $host_location_a, $second_locations, true ) && ! in_array( $host_location_b, $second_locations, true ), 'P2 no host Location is claimed', $checks );
	$scoped_patients = array_map( 'intval', (array) ( $preview['scope']['tables']['cpms_patients'] ?? array() ) );
	cpsd_pt_check( in_array( $second_patient, $scoped_patients, true ), 'P2 the second Clinic\'s dependent patient row is inside the deletion graph', $checks );
	cpsd_pt_check( ! in_array( $host_patient_a, $scoped_patients, true ) && ! in_array( $host_patient_b, $scoped_patients, true ), 'P2 no host patient row enters the deletion graph', $checks );

	$p2_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p2_result['ok'], 'P2 authorized teardown completes with two demo Clinics', $checks );
	cpsd_pt_check(
		null === cpsd_pt_scalar( 'cpms_patients', 'SELECT id FROM `' . $table( 'cpms_patients' ) . '` WHERE id = %d', array( $second_patient ) ),
		'P2 the second Clinic\'s dependent row was deleted',
		$checks
	);
	cpsd_pt_check(
		'suspended' === (string) cpsd_pt_scalar( 'cpms_clinics', 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $second_clinic ) ),
		'P2 the second Clinic is retained as an inert suspended anchor',
		$checks
	);
	$second_clinic_locations = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM `' . $table( 'cpms_locations' ) . '` WHERE clinic_id = %d', $second_clinic ) );
	$inactive = 0;
	foreach ( (array) $second_clinic_locations as $location_id ) {
		if ( 0 === (int) cpsd_pt_scalar( 'cpms_locations', 'SELECT is_active FROM `' . $table( 'cpms_locations' ) . '` WHERE id = %d', array( (int) $location_id ) ) ) {
			++$inactive;
		}
	}
	cpsd_pt_check( count( (array) $second_clinic_locations ) === $inactive, 'P2 every Location of the second Clinic is inert', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_digest, 'P2 every pre-existing host row is byte-identical', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P2 audit chain intact', $checks );
	cpsd_pt_check( 0 === count( cpsd_pt_synthetic_users() ), 'P2 no synthetic account survives', $checks );
	$report['P2']['second_clinic'] = $second_clinic;
	$report['P2']['anchors']       = $anchors;

	// ================================================================ scenario P3
	// A Clinic that names the demo Organization but predates the sealed baseline
	// cannot be proven demo-created: refuse BEFORE anything is destroyed.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live        = $manifest->load();
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$demo_org    = (int) $live['ids']['organization_id'];
	$baseline_c  = (array) $live['baseline'];
	$baseline_clinic_max = (int) ( $baseline_c['cpms']['cpms_clinics']['max_id'] ?? 0 );
	cpsd_pt_check( $baseline_clinic_max > 3, 'P3 the sealed baseline leaves free ids below its max (' . $baseline_clinic_max . ')', $checks );

	$planted_id = 0;
	for ( $candidate = 2; $candidate <= $baseline_clinic_max; $candidate++ ) {
		if ( null === cpsd_pt_scalar( 'cpms_clinics', 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $candidate ) ) ) {
			$planted_id = $candidate;
			break;
		}
	}
	cpsd_pt_check( $planted_id > 1, 'P3 a free pre-baseline id exists for the unreachable-Clinic simulation', $checks );
	$wpdb->insert(
		$table( 'cpms_clinics' ),
		array( 'id' => $planted_id, 'organization_id' => $demo_org, 'name' => 'Unproven Clinic ' . $tag, 'slug' => $tag . '-unproven', 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	cpsd_pt_check( '' === (string) $wpdb->last_error, 'P3 the unproven Clinic row exists under the demo Organization', $checks );

	$fixture_now = cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) );
	$users_now   = cpsd_pt_synthetic_users();
	$host_now    = cpsd_pt_host_digest( $platform, $host_clinics );
	$preview     = $teardown->preview( $manifest->load() );
	cpsd_pt_check( false === (bool) $preview['ok'], 'P3 the preview refuses an unprovable Clinic', $checks );
	$problems = implode( ' | ', (array) $preview['problems'] );
	cpsd_pt_check( false !== strpos( $problems, 'SCOPED_OWNERSHIP_UNPROVEN' ), 'P3 the preview reports SCOPED_OWNERSHIP_UNPROVEN: ' . $problems, $checks );
	cpsd_pt_check( false !== strpos( $problems, 'cpms_clinics=' . $planted_id ), 'P3 the diagnostic names the unprovable Clinic id (' . $planted_id . ')', $checks );
	cpsd_pt_check( ! in_array( $planted_id, array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_clinics'] ?? array() ) ), true ), 'P3 the unprovable Clinic is never claimed as demo-owned', $checks );

	$code = '';
	try {
		$teardown->execute( $manifest->load(), $admin_id );
	} catch ( DemoException $error ) {
		$code = $error->demo_code();
	} catch ( Throwable $error ) {
		$code = 'UNEXPECTED:' . get_class( $error );
	}
	cpsd_pt_check( 'SCOPED_OWNERSHIP_UNPROVEN' === $code, 'P3 execution refuses with SCOPED_OWNERSHIP_UNPROVEN (' . ( '' === $code ? 'no refusal' : $code ) . ')', $checks );
	cpsd_pt_check( cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) ) === $fixture_now, 'P3 the refusal deleted no demo row', $checks );
	cpsd_pt_check( cpsd_pt_synthetic_users() === $users_now, 'P3 the refusal deleted no synthetic account', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_now, 'P3 the refusal touched no host row', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P3 audit chain intact after the refusal', $checks );
	$state = $manifest->load();
	cpsd_pt_check( is_array( $state ) && 'active' === (string) $state['status'], 'P3 the fixture is still active and intact after the refusal', $checks );

	cpsd_pt_delete( $table( 'cpms_clinics' ), $planted_id );
	$preview = $teardown->preview( $manifest->load() );
	cpsd_pt_check( true === (bool) $preview['ok'], 'P3 preview passes once the unprovable Clinic is gone (' . implode( ' | ', (array) $preview['problems'] ) . ')', $checks );
	$p3_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p3_result['ok'], 'P3 authorized teardown completes afterwards', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_digest, 'P3 every pre-existing host row is byte-identical', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P3 audit chain intact after teardown', $checks );
	$report['P3'] = array( 'unproven_id' => $planted_id, 'refusal' => $code, 'baseline_max' => $baseline_clinic_max );

	echo (string) wp_json_encode(
		array(
			'ok'      => true,
			'checks'  => $checks,
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
			'file'     => $error->getFile() . ':' . $error->getLine(),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	// The JSON verdict is the report: the workflow parses it and fails the check
	// itself, so the annotation names the failing assertion instead of only
	// "process exited 1".
}
