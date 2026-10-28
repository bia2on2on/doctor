<?php
/**
 * Real-WordPress management-portal lifecycle drills for the scoped demo.
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
 *   P2  an ADDITIONAL Clinic registered by the demo's own path (its exact slug
 *       sealed as an intent BEFORE the row existed, its id sealed after
 *       creation) is provably demo-owned: it and its dependents are cleaned
 *       selectively, the Clinic/Location anchors are retained inert, no foreign
 *       row is claimed, and the teardown succeeds
 *   P3  a Clinic that names the demo Organization but predates the sealed
 *       baseline cannot be proven demo-owned: the preview and the execution
 *       both refuse with SCOPED_OWNERSHIP_UNPROVEN before any destructive
 *       stage, deleting nothing; after the row is removed the teardown
 *       completes normally
 *   P4  a GENUINE non-demo Clinic created under the demo Organization AFTER the
 *       baseline was sealed (the exact case the old id/timestamp inference got
 *       wrong) is never claimed: preview and execution refuse, nothing of it or
 *       of the fixture is deleted, and it survives byte-identical
 *   P5  the same for a foreign Clinic whose rows carry SYNTHETIC user ids
 *       (clinician, membership, invoice/payment actor): an overlapping user
 *       reference is never ownership, for the demo Organization or another one
 *   P6  a manipulated ownership ledger (edited record, broken chain, truncated
 *       history) is rejected with SCOPED_OWNERSHIP_LEDGER_INVALID and zero
 *       deletions, and the untouched ledger verifies again
 *   P7  an interrupted creation (after the sealed intent, and after the row was
 *       created but before its id was sealed) stays recoverable: the teardown
 *       refuses with SCOPED_OWNERSHIP_PENDING, the repair path registers the
 *       matching row or abandons the intent from sealed evidence only, the
 *       preview and the execution agree on the owned set, and claimed Clinics
 *       end suspended with the ledger discarded
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
use CpmsDemo\ScopedClinics;
use CpmsDemo\ScopedManifest;
use CpmsDemo\ScopedOwnership;
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

/**
 * Plant a complete, FOREIGN tenant that must never be demo-owned.
 *
 * The rows deliberately overlap the fixture in the ways that used to be taken as
 * evidence of ownership: the Clinic names the demo Organization (optional), a
 * clinician/membership/payment carries a SYNTHETIC user id, and every id is
 * above the sealed baseline. None of that proves anything; only the sealed
 * ownership record does. The function returns every planted id so the drill can
 * prove each row survived byte-identically.
 *
 * @param Platform $platform Adapter.
 * @param int      $org_id   Organization the foreign Clinic belongs to.
 * @param string   $tag      Unique drill tag.
 * @param string   $now      SQL timestamp.
 * @param int      $overlap_user Synthetic WordPress user id to overlap with.
 * @param string   $role      Valid CPMS clinic role key.
 * @return array<string,int|string> Planted ids.
 */
function cpsd_pt_plant_foreign( Platform $platform, int $org_id, string $tag, string $now, int $overlap_user, string $role ): array {
	global $wpdb;
	$t     = static fn ( string $short ): string => $platform->table( $short );
	$slug  = $tag . '-' . strtolower( bin2hex( random_bytes( 3 ) ) );
	$ids   = array( 'slug' => $slug );

	$ids['clinic'] = cpsd_pt_insert(
		$t( 'cpms_clinics' ),
		array( 'organization_id' => $org_id, 'name' => 'غیردمو ' . $slug, 'slug' => $slug, 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['location'] = cpsd_pt_insert(
		$t( 'cpms_locations' ),
		array( 'clinic_id' => $ids['clinic'], 'name' => 'شعبه غیردمو', 'slug' => $slug . '-loc', 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now )
	);
	// `cpms_clinicians.wp_user_id` is globally UNIQUE (migration 0007), so the
	// planted clinician starts unbound; a scenario that specifically needs a
	// synthetic-user overlap binds it explicitly and asserts the UPDATE affected
	// exactly one row.
	$ids['clinician'] = cpsd_pt_insert(
		$t( 'cpms_clinicians' ),
		array( 'clinic_id' => $ids['clinic'], 'wp_user_id' => null, 'full_name' => 'پزشک غیردمو', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['patient'] = cpsd_pt_insert(
		$t( 'cpms_patients' ),
		array( 'clinic_id' => $ids['clinic'], 'mrn' => $slug . '-mrn', 'first_name' => 'بیمار', 'last_name' => 'غیردمو', 'mobile' => '0935' . (string) random_int( 1000000, 9999999 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['visit'] = cpsd_pt_insert(
		$t( 'cpms_visits' ),
		array( 'clinic_id' => $ids['clinic'], 'location_id' => $ids['location'], 'clinician_id' => $ids['clinician'], 'patient_id' => $ids['patient'], 'source' => 'walk_in', 'status' => 'checked_in', 'visit_date' => gmdate( 'Y-m-d' ), 'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['invoice'] = cpsd_pt_insert(
		$t( 'cpms_invoices' ),
		array( 'clinic_id' => $ids['clinic'], 'invoice_number' => 'INV' . strtoupper( bin2hex( random_bytes( 4 ) ) ), 'patient_id' => $ids['patient'], 'visit_id' => $ids['visit'], 'status' => 'open', 'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10, 'currency' => 'IRR', 'paid_amount' => 0, 'balance' => 10, 'issued_by_wp_user_id' => $overlap_user, 'created_at' => $now, 'updated_at' => $now )
	);
	$ids['payment'] = cpsd_pt_insert(
		$t( 'cpms_payments' ),
		array( 'clinic_id' => $ids['clinic'], 'payment_number' => 'PAY' . strtoupper( bin2hex( random_bytes( 4 ) ) ), 'invoice_id' => $ids['invoice'], 'patient_id' => $ids['patient'], 'amount' => 5, 'method' => 'cash', 'idempotency_key' => $slug . '-idem', 'status' => 'captured', 'paid_at' => $now, 'received_by_wp_user_id' => $overlap_user, 'created_at' => $now )
	);
	$ids['membership'] = cpsd_pt_insert(
		$t( 'cpms_clinic_memberships' ),
		array( 'clinic_id' => $ids['clinic'], 'wp_user_id' => $overlap_user, 'role_key' => $role, 'scope_mode' => 'clinic', 'status' => 'active', 'is_primary' => 0, 'created_at' => $now, 'updated_at' => $now )
	);

	return $ids;
}

/**
 * Delete a planted foreign tenant in foreign-key order (drill cleanup only).
 *
 * @param Platform             $platform Adapter.
 * @param array<string,int|string> $ids Planted ids from cpsd_pt_plant_foreign().
 * @return void
 */
function cpsd_pt_remove_foreign( Platform $platform, array $ids ): void {
	global $wpdb;
	$t = static fn ( string $short ): string => $platform->table( $short );
	foreach ( array( 'cpms_payments' => 'payment', 'cpms_invoices' => 'invoice', 'cpms_visits' => 'visit', 'cpms_clinic_memberships' => 'membership', 'cpms_clinicians' => 'clinician', 'cpms_patients' => 'patient', 'cpms_locations' => 'location', 'cpms_clinics' => 'clinic' ) as $short => $key ) {
		$wpdb->delete( $t( $short ), array( 'id' => (int) $ids[ $key ] ), array( '%d' ) );
	}
}

/**
 * Row count of every foreign table a planted tenant touches.
 *
 * @param Platform $platform Adapter.
 * @param array<string,int|string> $ids Planted ids.
 * @return array<string,int>
 */
function cpsd_pt_foreign_counts( Platform $platform, array $ids ): array {
	global $wpdb;
	$t   = static fn ( string $short ): string => $platform->table( $short );
	$out = array();
	foreach ( array( 'cpms_clinics' => 'clinic', 'cpms_locations' => 'location', 'cpms_clinicians' => 'clinician', 'cpms_patients' => 'patient', 'cpms_visits' => 'visit', 'cpms_invoices' => 'invoice', 'cpms_payments' => 'payment', 'cpms_clinic_memberships' => 'membership' ) as $short => $key ) {
		$out[ $short ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $t( $short ) . '` WHERE id = %d', (int) $ids[ $key ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from a fixed list; value prepared.
	}

	return $out;
}

$checks = array();
$report = array(
	'P1' => array(),
	'P2' => array(),
	'P3' => array(),
	'P4' => array(),
	'P5' => array(),
	'P6' => array(),
	'P7' => array(),
);
try {
	if ( ! class_exists( App::class ) || ! class_exists( Platform::class ) ) {
		throw new RuntimeException( 'CPMS/companion not active' );
	}
	if ( ! class_exists( ManagementPortalController::class ) ) {
		throw new RuntimeException( 'management portal controller not loadable' );
	}
	$admin_id = 1;
	wp_set_current_user( $admin_id );
	// `wp eval-file` runs this file inside a function scope, so the database
	// handle must be imported explicitly.
	global $wpdb;
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

	// Stage 2: the portal CANNOT create additional Clinics in scoped demo mode.
	// The policy is server-side, not a hidden button: an explicit DENY row for
	// the production create capability, honoured by the production resolver.
	cpsd_pt_check(
		false === $organizations_service->can( $manager_id, $demo_org, 'cpms_org_clinic_create' ),
		'P1 the production resolver denies cpms_org_clinic_create to the synthetic Organization manager',
		$checks
	);
	$deny_row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT c.effect FROM `' . $table( 'cpms_organization_membership_capabilities' ) . '` c'
			. ' JOIN `' . $table( 'cpms_organization_memberships' ) . '` m ON m.id = c.membership_id'
			. ' WHERE m.organization_id = %d AND m.wp_user_id = %d AND c.capability = %s LIMIT 1',
			$demo_org,
			$manager_id,
			'cpms_org_clinic_create'
		),
		ARRAY_A
	);
	cpsd_pt_check( is_array( $deny_row ) && 'deny' === (string) $deny_row['effect'], 'P1 the DENY capability row is stored for the synthetic manager membership', $checks );
	$clinics_before = (int) cpsd_pt_scalar( 'SELECT COUNT(*) FROM `' . $table( 'cpms_clinics' ) . '` WHERE organization_id = %d', array( $demo_org ) );
	$refused_create = ManagementPortalController::dispatch(
		$manager_id,
		'org_create_clinic',
		array( 'organization_id' => $demo_org, 'cpms_entity_name' => 'درمانگاه غیرمجاز ' . $tag, 'slug' => $tag . '-forbidden', 'timezone' => 'Asia/Tehran', 'location_name' => 'شعبه غیرمجاز' )
	);
	cpsd_pt_check( false === (bool) $refused_create['ok'], 'P1 the real portal refuses to create a Clinic under the demo Organization in scoped mode', $checks );
	cpsd_pt_check( null === cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE slug = %s', array( $tag . '-forbidden' ) ), 'P1 the refused portal call created no Clinic row', $checks );
	cpsd_pt_check(
		$clinics_before === (int) cpsd_pt_scalar( 'SELECT COUNT(*) FROM `' . $table( 'cpms_clinics' ) . '` WHERE organization_id = %d', array( $demo_org ) ),
		'P1 the demo Organization still holds exactly its own Clinic',
		$checks
	);
	cpsd_pt_check( true === (bool) $organizations_service->can( $manager_id, $demo_org, 'cpms_org_clinic_lifecycle' ), 'P1 the lifecycle authority of the synthetic manager is untouched by the create DENY', $checks );

	$deny = ManagementPortalController::dispatch( $host_user, 'org_clinic_status', array( 'organization_id' => $demo_org, 'clinic_id' => $demo_clinic, 'active' => false ) );
	cpsd_pt_check( false === (bool) $deny['ok'], 'P1 an unauthorized account cannot change the demo Clinic lifecycle (portal refused)', $checks );
	cpsd_pt_check( 'active' === (string) cpsd_pt_scalar( 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $demo_clinic ) ), 'P1 the refused attempt left the demo Clinic active', $checks );

	$deactivate = ManagementPortalController::dispatch( $manager_id, 'org_clinic_status', array( 'organization_id' => $demo_org, 'clinic_id' => $demo_clinic, 'active' => false ) );
	cpsd_pt_check( true === (bool) $deactivate['ok'], 'P1 the synthetic Organization manager deactivated the demo Clinic through the real portal', $checks );
	$stamped = cpsd_pt_scalar( 'SELECT status_updated_by_wp_user_id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $demo_clinic ) );
	cpsd_pt_check( (int) $stamped === $manager_id, 'P1 the lifecycle writer stamped status_updated_by_wp_user_id with the synthetic manager (' . (string) $stamped . ')', $checks );
	cpsd_pt_check( 'suspended' === (string) cpsd_pt_scalar( 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $demo_clinic ) ), 'P1 the demo Clinic is suspended after the portal action', $checks );
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
	// An ADDITIONAL demo Clinic is demo-owned only because the sealed ledger says
	// so: its exact slug was committed BEFORE the row existed and its id was sealed
	// after creation. It and its dependents are cleaned selectively, the Clinic and
	// its Locations are retained inert, and no foreign row is claimed.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live        = $manifest->load();
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$demo_org    = (int) $live['ids']['organization_id'];
	$fixture     = (string) $live['fixture_id'];
	$ownership   = new ScopedOwnership( $env, $manifest );
	$extra_svc   = new ScopedClinics( $env, $platform, $manifest, $ownership );

	$summary = $ownership->summary( $fixture );
	cpsd_pt_check( true === (bool) $summary['ok'], 'P2 the ownership ledger of a fresh fixture verifies', $checks );
	cpsd_pt_check( array( $demo_clinic ) === array_map( 'intval', (array) $summary['clinic_ids'] ), 'P2 the ledger names exactly the fixture Clinic before any addition', $checks );

	$extra         = $extra_svc->create( $manifest->load(), $admin_id );
	$second_clinic = (int) $extra['clinic_id'];
	cpsd_pt_check( $second_clinic > 1 && $second_clinic !== $demo_clinic, 'P2 the demo created a distinct additional Clinic (' . $second_clinic . ')', $checks );
	cpsd_pt_check(
		(int) $demo_org === (int) cpsd_pt_scalar( 'SELECT organization_id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $second_clinic ) ),
		'P2 the additional Clinic belongs to the demo Organization',
		$checks
	);
	$summary = $ownership->summary( $fixture );
	cpsd_pt_check( in_array( $second_clinic, array_map( 'intval', (array) $summary['clinic_ids'] ), true ), 'P2 the created Clinic id is sealed in the ownership ledger', $checks );
	cpsd_pt_check( array() === (array) $summary['pending'], 'P2 the pre-committed intent is closed by the sealed id', $checks );
	$kinds = array_map( static fn ( array $entry ): string => (string) ( $entry['kind'] ?? '' ), (array) $ownership->inspect( $fixture )['entries'] );
	cpsd_pt_check( array( 'open', 'intent', 'clinic' ) === array_slice( $kinds, 0, 3 ), 'P2 the ledger records open -> intent -> clinic in that order', $checks );
	$intent_entry = (array) $ownership->inspect( $fixture )['entries'][1];
	cpsd_pt_check( (string) ( $intent_entry['payload']['slug'] ?? '' ) === (string) $extra['slug'], 'P2 the intent sealed the exact slug of the created Clinic', $checks );

	// A dependent row inside the additional Clinic, created by a synthetic
	// account: it must be cleaned selectively with the Clinic, never left as
	// residue and never mistaken for a foreign row.
	$second_patient = cpsd_pt_insert(
		$table( 'cpms_patients' ),
		array( 'clinic_id' => $second_clinic, 'mrn' => 'P2-' . strtoupper( bin2hex( random_bytes( 3 ) ) ), 'first_name' => 'دوم', 'last_name' => 'بیمار ' . $tag, 'mobile' => '0912' . (string) random_int( 1000000, 9999999 ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now )
	);

	$preview = $teardown->preview( $manifest->load() );
	cpsd_pt_check( true === (bool) $preview['ok'], 'P2 teardown preview passes (' . implode( ' | ', (array) $preview['problems'] ) . ')', $checks );
	$anchors = array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_clinics'] ?? array() ) );
	cpsd_pt_check( in_array( $demo_clinic, $anchors, true ), 'P2 the fixture Clinic is a demo-owned anchor', $checks );
	cpsd_pt_check( in_array( $second_clinic, $anchors, true ), 'P2 the registered additional Clinic is provably demo-owned and claimed', $checks );
	cpsd_pt_check( ! in_array( $host_clinic_a, $anchors, true ) && ! in_array( $host_clinic_b, $anchors, true ), 'P2 no pre-existing Clinic is claimed', $checks );
	$second_locations = array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_locations'] ?? array() ) );
	cpsd_pt_check( ! in_array( $host_location_a, $second_locations, true ) && ! in_array( $host_location_b, $second_locations, true ), 'P2 no host Location is claimed', $checks );
	$scoped_patients = array_map( 'intval', (array) ( $preview['scope']['tables']['cpms_patients'] ?? array() ) );
	cpsd_pt_check( in_array( $second_patient, $scoped_patients, true ), 'P2 the additional Clinic\'s dependent patient row is inside the deletion graph', $checks );
	cpsd_pt_check( ! in_array( $host_patient_a, $scoped_patients, true ) && ! in_array( $host_patient_b, $scoped_patients, true ), 'P2 no host patient row enters the deletion graph', $checks );

	$p2_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p2_result['ok'], 'P2 authorized teardown completes with two demo Clinics', $checks );
	cpsd_pt_check(
		null === cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_patients' ) . '` WHERE id = %d', array( $second_patient ) ),
		'P2 the additional Clinic\'s dependent row was deleted',
		$checks
	);
	cpsd_pt_check(
		'suspended' === (string) cpsd_pt_scalar( 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $second_clinic ) ),
		'P2 the additional Clinic is retained as an inert suspended anchor',
		$checks
	);
	$second_clinic_locations = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM `' . $table( 'cpms_locations' ) . '` WHERE clinic_id = %d', $second_clinic ) );
	$inactive = 0;
	foreach ( (array) $second_clinic_locations as $location_id ) {
		if ( 0 === (int) cpsd_pt_scalar( 'SELECT is_active FROM `' . $table( 'cpms_locations' ) . '` WHERE id = %d', array( (int) $location_id ) ) ) {
			++$inactive;
		}
	}
	cpsd_pt_check( array() !== (array) $second_clinic_locations && count( (array) $second_clinic_locations ) === $inactive, 'P2 every Location of the additional Clinic is inert', $checks );
	cpsd_pt_check( null === $manifest->load(), 'P2 the manifest is released', $checks );
	cpsd_pt_check( false === $ownership->exists(), 'P2 the ownership ledger is discarded only after the teardown is proven clean', $checks );
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
		if ( null === cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $candidate ) ) ) {
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

	// ================================================================ scenario P4
	// A FOREIGN Clinic created AFTER the sealed baseline under the demo
	// Organization is never claimed. This is the exact data-safety risk: it names
	// the demo Organization, its id postdates the seal, and it is not in the
	// sealed ownership ledger, so nothing about it is provable.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live        = $manifest->load();
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$demo_org    = (int) $live['ids']['organization_id'];
	$fixture     = (string) $live['fixture_id'];
	$baseline_c  = (array) $live['baseline'];
	$baseline_clinic_max = (int) ( $baseline_c['cpms']['cpms_clinics']['max_id'] ?? 0 );
	$ownership   = new ScopedOwnership( $env, $manifest );
	$role        = (string) ( $platform->role_keys()['receptionist'] ?? 'cpms_receptionist' );

	$foreign = cpsd_pt_plant_foreign( $platform, $demo_org, $tag . '-p4', $now, $host_user, $role );
	cpsd_pt_check( (int) $foreign['clinic'] > $baseline_clinic_max, 'P4 the foreign Clinic postdates the sealed baseline (' . (int) $foreign['clinic'] . ' > ' . $baseline_clinic_max . ')', $checks );
	cpsd_pt_check( (int) $demo_org === (int) cpsd_pt_scalar( 'SELECT organization_id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( (int) $foreign['clinic'] ) ), 'P4 the foreign Clinic names the demo Organization', $checks );

	$foreign_counts_before = cpsd_pt_foreign_counts( $platform, $foreign );
	$fixture_counts_before = cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) );
	$users_before          = cpsd_pt_synthetic_users();
	$host_now              = cpsd_pt_host_digest( $platform, $host_clinics );

	$preview = $teardown->preview( $manifest->load() );
	$problems = implode( ' | ', (array) $preview['problems'] );
	cpsd_pt_check( false === (bool) $preview['ok'], 'P4 the preview refuses while an unproven Clinic exists under the demo Organization', $checks );
	cpsd_pt_check( false !== strpos( $problems, 'SCOPED_OWNERSHIP_UNPROVEN' ), 'P4 the preview reports SCOPED_OWNERSHIP_UNPROVEN: ' . $problems, $checks );
	cpsd_pt_check( false !== strpos( $problems, 'cpms_clinics=' . (int) $foreign['clinic'] ), 'P4 the diagnostic names the foreign Clinic id', $checks );
	$anchors = array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_clinics'] ?? array() ) );
	cpsd_pt_check( ! in_array( (int) $foreign['clinic'], $anchors, true ), 'P4 the foreign Clinic is never claimed as demo-owned', $checks );
	foreach ( array( 'cpms_patients' => 'patient', 'cpms_visits' => 'visit', 'cpms_invoices' => 'invoice', 'cpms_payments' => 'payment', 'cpms_clinicians' => 'clinician' ) as $short => $key ) {
		$claimed = array_map( 'intval', (array) ( $preview['scope']['tables'][ $short ] ?? array() ) );
		cpsd_pt_check( ! in_array( (int) $foreign[ $key ], $claimed, true ), 'P4 the foreign ' . $short . ' row is outside the deletion graph', $checks );
	}

	$code = '';
	try {
		$teardown->execute( $manifest->load(), $admin_id );
	} catch ( DemoException $error ) {
		$code = $error->demo_code();
	} catch ( Throwable $error ) {
		$code = 'UNEXPECTED:' . get_class( $error );
	}
	cpsd_pt_check( 'SCOPED_OWNERSHIP_UNPROVEN' === $code, 'P4 execution refuses with SCOPED_OWNERSHIP_UNPROVEN (' . ( '' === $code ? 'no refusal' : $code ) . ')', $checks );
	cpsd_pt_check( cpsd_pt_foreign_counts( $platform, $foreign ) === $foreign_counts_before, 'P4 every foreign patient/clinical/invoice/payment/membership row survives unchanged', $checks );
	cpsd_pt_check( cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) ) === $fixture_counts_before, 'P4 the refusal deleted no demo row', $checks );
	cpsd_pt_check( cpsd_pt_synthetic_users() === $users_before, 'P4 the refusal deleted no synthetic account', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_now, 'P4 the refusal touched no pre-existing host row', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P4 audit chain intact after the refusal', $checks );
	$state = $manifest->load();
	cpsd_pt_check( is_array( $state ) && 'active' === (string) $state['status'], 'P4 the fixture is still active and intact after the refusal', $checks );

	// The operator resolves it out of band (here: removes the foreign tenant), and
	// only then may the demo be cleaned.
	cpsd_pt_remove_foreign( $platform, $foreign );
	$preview = $teardown->preview( $manifest->load() );
	cpsd_pt_check( true === (bool) $preview['ok'], 'P4 preview passes once the foreign Clinic is gone (' . implode( ' | ', (array) $preview['problems'] ) . ')', $checks );
	$p4_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p4_result['ok'], 'P4 the demo teardown completes afterwards', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_digest, 'P4 every pre-existing host row is byte-identical', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P4 audit chain intact after teardown', $checks );
	$report['P4'] = array( 'foreign_clinic' => (int) $foreign['clinic'], 'baseline_max' => $baseline_clinic_max, 'refusal' => $code );

	// ================================================================ scenario P5
	// Overlapping USER references are never ownership. A foreign tenant whose
	// clinician, membership and payment rows carry SYNTHETIC WordPress user ids
	// must not be claimed, and a foreign Clinic of another Organization that does
	// the same must survive untouched.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live        = $manifest->load();
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$demo_org    = (int) $live['ids']['organization_id'];
	$manager_id  = (int) $live['ids']['user_ids']['organization_manager'];
	$overlap     = (int) $live['ids']['user_ids']['receptionist'];

	$foreign_demo_org = cpsd_pt_plant_foreign( $platform, $demo_org, $tag . '-p5a', $now, $manager_id, $role );
	$foreign_host_org = cpsd_pt_plant_foreign( $platform, $host_org, $tag . '-p5b', $now, $overlap, $role );
	$bound = $wpdb->update(
		$table( 'cpms_clinicians' ),
		array( 'wp_user_id' => $manager_id ),
		array( 'id' => (int) $foreign_demo_org['clinician'] ),
		array( '%d' ),
		array( '%d' )
	);
	cpsd_pt_check( 1 === (int) $bound, 'P5 the foreign Clinic\'s clinician now references the synthetic Organization manager', $checks );
	$bound_host = $wpdb->update(
		$table( 'cpms_clinicians' ),
		array( 'wp_user_id' => $overlap ),
		array( 'id' => (int) $foreign_host_org['clinician'] ),
		array( '%d' ),
		array( '%d' )
	);
	cpsd_pt_check( 1 === (int) $bound_host, 'P5 the other Organization\'s foreign clinician references a different synthetic persona', $checks );
	$counts_demo = cpsd_pt_foreign_counts( $platform, $foreign_demo_org );
	$counts_host = cpsd_pt_foreign_counts( $platform, $foreign_host_org );
	$fixture_counts_before = cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) );
	$users_before          = cpsd_pt_synthetic_users();

	$preview  = $teardown->preview( $manifest->load() );
	$problems = implode( ' | ', (array) $preview['problems'] );
	cpsd_pt_check( false === (bool) $preview['ok'], 'P5 the preview refuses while overlapping foreign rows exist', $checks );
	cpsd_pt_check( false !== strpos( $problems, 'SCOPED_OWNERSHIP_UNPROVEN' ), 'P5 a foreign Clinic that carries a synthetic user id is still unproven: ' . $problems, $checks );
	cpsd_pt_check( false !== strpos( $problems, 'SCOPED_FOREIGN_DEPENDENCY' ), 'P5 rows outside the demo that depend on a synthetic account are reported: ' . $problems, $checks );
	$anchors = array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_clinics'] ?? array() ) );
	cpsd_pt_check( ! in_array( (int) $foreign_demo_org['clinic'], $anchors, true ), 'P5 the overlapping foreign Clinic is never claimed', $checks );
	$claimed_memberships = array_map( 'intval', (array) ( $preview['scope']['tables']['cpms_clinic_memberships'] ?? array() ) );
	cpsd_pt_check( ! in_array( (int) $foreign_demo_org['membership'], $claimed_memberships, true ), 'P5 a foreign membership that names a synthetic user is not claimed', $checks );

	$code = '';
	try {
		$teardown->execute( $manifest->load(), $admin_id );
	} catch ( DemoException $error ) {
		$code = $error->demo_code();
	} catch ( Throwable $error ) {
		$code = 'UNEXPECTED:' . get_class( $error );
	}
	cpsd_pt_check( in_array( $code, array( 'SCOPED_OWNERSHIP_UNPROVEN', 'SCOPED_FOREIGN_DEPENDENCY' ), true ), 'P5 execution refuses before any destructive stage (' . ( '' === $code ? 'no refusal' : $code ) . ')', $checks );
	cpsd_pt_check( cpsd_pt_foreign_counts( $platform, $foreign_demo_org ) === $counts_demo, 'P5 the foreign tenant under the demo Organization survives unchanged', $checks );
	cpsd_pt_check( cpsd_pt_foreign_counts( $platform, $foreign_host_org ) === $counts_host, 'P5 the foreign tenant under another Organization survives unchanged', $checks );
	cpsd_pt_check( cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) ) === $fixture_counts_before, 'P5 no demo row was deleted by the refusal', $checks );
	cpsd_pt_check( cpsd_pt_synthetic_users() === $users_before, 'P5 no synthetic account was deleted by the refusal', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P5 audit chain intact after the refusal', $checks );
	$report['P5'] = array( 'demo_org_foreign_clinic' => (int) $foreign_demo_org['clinic'], 'host_org_foreign_clinic' => (int) $foreign_host_org['clinic'], 'refusal' => $code );

	cpsd_pt_remove_foreign( $platform, $foreign_demo_org );
	cpsd_pt_remove_foreign( $platform, $foreign_host_org );
	$preview = $teardown->preview( $manifest->load() );
	cpsd_pt_check( true === (bool) $preview['ok'], 'P5 preview passes once the overlapping foreign rows are gone (' . implode( ' | ', (array) $preview['problems'] ) . ')', $checks );
	$p5_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p5_result['ok'], 'P5 the demo teardown completes afterwards', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_digest, 'P5 every pre-existing host row is byte-identical', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P5 audit chain intact after teardown', $checks );

	// ================================================================ scenario P6
	// Manipulated ownership metadata is detected, not obeyed. Editing a sealed id
	// or truncating the chain both make the ledger unverifiable, and an
	// unverifiable ledger blocks every destructive stage.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live        = $manifest->load();
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$fixture     = (string) $live['fixture_id'];
	$ownership   = new ScopedOwnership( $env, $manifest );
	$extra_svc   = new ScopedClinics( $env, $platform, $manifest, $ownership );
	$extra       = $extra_svc->create( $manifest->load(), $admin_id );
	$tamper_clinic = (int) $extra['clinic_id'];
	$original      = get_option( ScopedOwnership::OPTION );
	cpsd_pt_check( is_array( $original ) && array() !== (array) $original['entries'], 'P6 the sealed ledger exists before tampering', $checks );
	$fixture_counts_before = cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) );
	$users_before          = cpsd_pt_synthetic_users();

	// (a) re-point a sealed clinic id at another Clinic.
	$edited = $original;
	$edited['entries'][2]['payload']['clinic_id'] = $demo_clinic;
	update_option( ScopedOwnership::OPTION, $edited );
	$preview  = $teardown->preview( $manifest->load() );
	$problems = implode( ' | ', (array) $preview['problems'] );
	cpsd_pt_check( false === (bool) $preview['ok'] && false !== strpos( $problems, 'SCOPED_OWNERSHIP_LEDGER_INVALID' ), 'P6 an edited ledger record is rejected: ' . $problems, $checks );
	$code = '';
	try {
		$teardown->execute( $manifest->load(), $admin_id );
	} catch ( DemoException $error ) {
		$code = $error->demo_code();
	} catch ( Throwable $error ) {
		$code = 'UNEXPECTED:' . get_class( $error );
	}
	cpsd_pt_check( 'SCOPED_OWNERSHIP_LEDGER_INVALID' === $code, 'P6 execution refuses a manipulated ledger (' . ( '' === $code ? 'no refusal' : $code ) . ')', $checks );
	cpsd_pt_check( null !== cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $tamper_clinic ) ), 'P6 the manipulated ledger deleted no Clinic', $checks );

	// (b) truncate the chain.
	$truncated = $original;
	array_pop( $truncated['entries'] );
	update_option( ScopedOwnership::OPTION, $truncated );
	$preview  = $teardown->preview( $manifest->load() );
	$problems = implode( ' | ', (array) $preview['problems'] );
	cpsd_pt_check( false === (bool) $preview['ok'] && false !== strpos( $problems, 'SCOPED_OWNERSHIP_LEDGER_INVALID' ), 'P6 a truncated ledger is rejected: ' . $problems, $checks );
	$code = '';
	try {
		$teardown->execute( $manifest->load(), $admin_id );
	} catch ( DemoException $error ) {
		$code = $error->demo_code();
	} catch ( Throwable $error ) {
		$code = 'UNEXPECTED:' . get_class( $error );
	}
	cpsd_pt_check( 'SCOPED_OWNERSHIP_LEDGER_INVALID' === $code, 'P6 execution refuses a truncated ledger (' . ( '' === $code ? 'no refusal' : $code ) . ')', $checks );
	cpsd_pt_check( cpsd_pt_fixture_counts( $platform, array( $demo_clinic ) ) === $fixture_counts_before, 'P6 neither tampering attempt deleted a demo row', $checks );
	cpsd_pt_check( cpsd_pt_synthetic_users() === $users_before, 'P6 neither tampering attempt deleted a synthetic account', $checks );

	// (c) the untouched ledger verifies again, and the teardown proceeds.
	update_option( ScopedOwnership::OPTION, $original );
	$preview = $teardown->preview( $manifest->load() );
	cpsd_pt_check( true === (bool) $preview['ok'], 'P6 the restored ledger verifies and the preview passes (' . implode( ' | ', (array) $preview['problems'] ) . ')', $checks );
	$p6_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p6_result['ok'], 'P6 the teardown completes with the restored ledger', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_digest, 'P6 every pre-existing host row is byte-identical', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P6 audit chain intact after teardown', $checks );
	$report['P6'] = array( 'tampered_clinic' => $tamper_clinic, 'refusals' => 2 );

	// ================================================================ scenario P7
	// An interrupted registration stays recoverable WITHOUT ever guessing: the
	// pending intent blocks the teardown, an administrator either registers the
	// Clinic whose slug was sealed beforehand or abandons an intent that provably
	// created nothing, and preview and execution agree on the outcome.
	wp_set_current_user( $admin_id );
	$manifest->enable_mode( $admin_id );
	$provisioner->provision( $admin_id );
	$live        = $manifest->load();
	$demo_clinic = (int) $live['ids']['clinic_id'];
	$org_fixture = (string) $live['fixture_id'];
	$ownership   = new ScopedOwnership( $env, $manifest );
	$extra_svc   = new ScopedClinics( $env, $platform, $manifest, $ownership );

	// (a) interrupted after the intent was sealed, before any row existed.
	$env->set_fault_injector(
		static function ( string $reached ): void {
			if ( 'scoped_ownership:after-intent' === $reached ) {
				throw new RuntimeException( 'INJECTED_FAULT:after-intent' );
			}
		}
	);
	$interrupted = false;
	try {
		$extra_svc->create( $manifest->load(), $admin_id );
	} catch ( Throwable $error ) {
		$interrupted = true;
	}
	$env->set_fault_injector( null );
	cpsd_pt_check( $interrupted, 'P7 the creation run was interrupted after the intent was sealed', $checks );
	$summary = $ownership->summary( $org_fixture );
	cpsd_pt_check( true === (bool) $summary['ok'] && 1 === count( (array) $summary['pending'] ), 'P7 exactly one pending intent is sealed', $checks );
	$intent = (array) $summary['pending'][0];
	cpsd_pt_check( null === cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE slug = %s', array( (string) $intent['slug'] ) ), 'P7 the interrupted run created no Clinic row', $checks );
	$preview  = $teardown->preview( $manifest->load() );
	$problems = implode( ' | ', (array) $preview['problems'] );
	cpsd_pt_check( false === (bool) $preview['ok'] && false !== strpos( $problems, 'SCOPED_OWNERSHIP_PENDING' ), 'P7 a pending intent blocks the preview: ' . $problems, $checks );
	$code = '';
	try {
		$teardown->execute( $manifest->load(), $admin_id );
	} catch ( DemoException $error ) {
		$code = $error->demo_code();
	} catch ( Throwable $error ) {
		$code = 'UNEXPECTED:' . get_class( $error );
	}
	cpsd_pt_check( 'SCOPED_OWNERSHIP_PENDING' === $code, 'P7 execution refuses while an intent is pending (' . ( '' === $code ? 'no refusal' : $code ) . ')', $checks );
	cpsd_pt_check( 0 < count( cpsd_pt_synthetic_users() ), 'P7 the refusal kept every synthetic account (the fixture is still live)', $checks );
	$abandoned = $extra_svc->resolve( $manifest->load(), (string) $intent['token'], false );
	cpsd_pt_check( 'abandoned' === (string) $abandoned['decision'] && array() === (array) $ownership->summary( $org_fixture )['pending'], 'P7 an intent that provably created nothing can be abandoned', $checks );

	// (b) interrupted after the Clinic was created, before it was registered.
	$env->set_fault_injector(
		static function ( string $reached ): void {
			if ( 'scoped_ownership:after-create' === $reached ) {
				throw new RuntimeException( 'INJECTED_FAULT:after-create' );
			}
		}
	);
	$interrupted = false;
	try {
		$extra_svc->create( $manifest->load(), $admin_id );
	} catch ( Throwable $error ) {
		$interrupted = true;
	}
	$env->set_fault_injector( null );
	cpsd_pt_check( $interrupted, 'P7 the creation run was interrupted after the Clinic was created', $checks );
	$summary = $ownership->summary( $org_fixture );
	cpsd_pt_check( 1 === count( (array) $summary['pending'] ), 'P7 the created-but-unregistered Clinic leaves exactly one pending intent', $checks );
	$pending_clinic = (int) cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE slug = %s', array( (string) $summary['pending'][0]['slug'] ) );
	cpsd_pt_check( $pending_clinic > 1, 'P7 the Clinic exists but is not registered yet (' . $pending_clinic . ')', $checks );
	$preview  = $teardown->preview( $manifest->load() );
	$problems = implode( ' | ', (array) $preview['problems'] );
	cpsd_pt_check( false === (bool) $preview['ok'], 'P7 the preview refuses the half-registered state: ' . $problems, $checks );
	$code = '';
	try {
		$teardown->execute( $manifest->load(), $admin_id );
	} catch ( DemoException $error ) {
		$code = $error->demo_code();
	} catch ( Throwable $error ) {
		$code = 'UNEXPECTED:' . get_class( $error );
	}
	cpsd_pt_check( 'SCOPED_OWNERSHIP_PENDING' === $code, 'P7 execution refuses the half-registered state (' . ( '' === $code ? 'no refusal' : $code ) . ')', $checks );
	cpsd_pt_check( null !== cpsd_pt_scalar( 'SELECT id FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( $pending_clinic ) ), 'P7 the refusal never deleted the created Clinic it could not prove', $checks );

	// The administrator resolves it from the pre-committed slug evidence.
	$claimed = $extra_svc->resolve( $manifest->load(), (string) $summary['pending'][0]['token'], true );
	cpsd_pt_check( 'registered' === (string) $claimed['decision'] && (int) $claimed['clinic_id'] === $pending_clinic, 'P7 the interrupted Clinic is registered from the sealed slug evidence', $checks );
	cpsd_pt_check( array() === (array) $ownership->summary( $org_fixture )['pending'], 'P7 the repair closes the intent', $checks );

	// preview and execution must agree, so the previewed row set is compared with
	// what the execution actually removed and preserved.
	$preview   = $teardown->preview( $manifest->load() );
	cpsd_pt_check( true === (bool) $preview['ok'], 'P7 the preview passes after the repair (' . implode( ' | ', (array) $preview['problems'] ) . ')', $checks );
	$anchors   = array_map( 'intval', (array) ( $preview['scope']['anchors']['cpms_clinics'] ?? array() ) );
	cpsd_pt_check( in_array( $pending_clinic, $anchors, true ), 'P7 the repaired Clinic is claimed in the preview', $checks );
	$predicted_tables = (array) ( $preview['scope']['tables'] ?? array() );
	$p7_result = $teardown->execute( $manifest->load(), $admin_id );
	cpsd_pt_check( true === (bool) $p7_result['ok'], 'P7 the teardown completes after the repair', $checks );
	$agreement = true;
	foreach ( $predicted_tables as $short => $ids ) {
		if ( in_array( $short, array( 'cpms_clinics', 'cpms_organizations', 'cpms_locations', 'cpms_audit_logs' ), true ) ) {
			continue;
		}
		foreach ( (array) $ids as $row_id ) {
			if ( null !== cpsd_pt_scalar( 'SELECT id FROM `' . $table( (string) $short ) . '` WHERE id = %d', array( (int) $row_id ) ) ) {
				$agreement = false;
			}
		}
	}
	cpsd_pt_check( $agreement, 'P7 every row the preview predicted as deletable was actually deleted (preview == execution)', $checks );
	$suspended = 0;
	foreach ( $anchors as $anchor_id ) {
		if ( 'suspended' === (string) cpsd_pt_scalar( 'SELECT status FROM `' . $table( 'cpms_clinics' ) . '` WHERE id = %d', array( (int) $anchor_id ) ) ) {
			++$suspended;
		}
	}
	cpsd_pt_check( count( $anchors ) === $suspended, 'P7 every Clinic the preview claimed is now an inert suspended anchor', $checks );
	cpsd_pt_check( cpsd_pt_host_digest( $platform, $host_clinics ) === $host_digest, 'P7 every pre-existing host row is byte-identical', $checks );
	cpsd_pt_check( (bool) $audit_guard->verify_chain()['ok'], 'P7 audit chain intact after the repair and teardown', $checks );
	cpsd_pt_check( false === $ownership->exists(), 'P7 the ledger is gone after the clean teardown', $checks );
	$report['P7'] = array( 'repaired_clinic' => $pending_clinic, 'anchors' => $anchors );

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
