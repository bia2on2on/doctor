<?php
/**
 * Stage and finish the Owner incident on a real WordPress + MySQL/MariaDB site.
 *
 * WHY THIS DRIVER EXISTS
 * ----------------------
 * The incident is a *state*, not a code path that a single request produces:
 * a populated private site where the eight synthetic accounts and every
 * synthetic row existed, the manifest said `failed`, the sealed pre-provisioning
 * baseline was gone, the teardown refused `BASELINE_MISSING`, and the preflight
 * refused a second fixture. The package offered no action that could reach the
 * eight portals from there.
 *
 * `stage` reproduces that state exactly the way the old failure handler produced
 * it: the failing stage's pre-seal snapshot is written over the sealed record and
 * the pending baseline copy is dropped. This is a REPRODUCTION of the defect, not
 * a claim about which exception the Owner's run actually hit - that is unknowable
 * from the surviving state, and pretending otherwise would be dishonest.
 *
 * `finish` then proves, against the real database and after the Chromium phase
 * clicked the one Persian action, that:
 *   - the repair created and deleted no user, patient, appointment, invoice or
 *     payment row;
 *   - the destructive direction is STILL refused (`BASELINE_MISSING`);
 *   - parking archives the whole sealed record, deletes nothing, and lets a
 *     fresh, fully isolated demo be provisioned and torn down cleanly;
 *   - the parked fixture is left for an explicit, human-reviewed resolution.
 *
 * Run with:
 *   CPSD_INCIDENT_MODE=stage  wp eval-file tests/scoped-demo/owner-incident.php ...
 *   CPSD_INCIDENT_MODE=finish wp eval-file tests/scoped-demo/owner-incident.php ...
 *
 * It prints one JSON line and never deletes an ambiguous row.
 *
 * @package CpmsDisposableDemo
 */

use CpmsDemo\Credentials;
use CpmsDemo\Env;
use CpmsDemo\Platform;
use CpmsDemo\ScopedAuditGuard;
use CpmsDemo\ScopedBaseline;
use CpmsDemo\ScopedManifest;
use CpmsDemo\ScopedPreflight;
use CpmsDemo\ScopedProvisioner;
use CpmsDemo\ScopedRecovery;
use CpmsDemo\ScopedTeardown;

defined( 'ABSPATH' ) || exit;

$cpsd_mode = (string) ( getenv( 'CPSD_INCIDENT_MODE' ) ?: 'stage' );
// Rows a repair may never create or delete. Audit logs are excluded on purpose:
// an append-only audit row is the evidence a repair leaves behind.
$cpsd_counted_tables = array(
	'cpms_organizations',
	'cpms_clinics',
	'cpms_locations',
	'cpms_patients',
	'cpms_appointments',
	'cpms_visits',
	'cpms_invoices',
	'cpms_payments',
	'cpms_schedule_slots',
	'cpms_clinic_memberships',
	'cpms_organization_memberships',
);

/**
 * Snapshot of everything a repair must not change.
 *
 * @param Env      $env      Environment.
 * @param Platform $platform Adapter.
 * @param array<int,string> $tables Short table names.
 * @return array<string,int>
 */
$cpsd_snapshot = static function ( Env $env, Platform $platform, array $tables ): array {
	$snapshot           = array( 'users' => count( $env->all_users() ) );
	foreach ( $tables as $short ) {
		$snapshot[ $short ] = $platform->count_all( $short );
	}

	return $snapshot;
};

/**
 * Accounts under the synthetic prefix, optionally for ONE fixture.
 *
 * A CI site is shared by several drills in the same job, and a PARKED fixture
 * keeps its eight accounts on purpose - that is what parking means. A site-wide
 * count would therefore report another fixture's preserved evidence as this
 * drill's failure, so the assertions below are fixture-scoped and the site-wide
 * figure is reported next to them as evidence rather than used as a threshold.
 *
 * @param Env    $env     Environment.
 * @param string $fixture Fixture id to restrict to, or '' for the whole site.
 * @return int
 */
$cpsd_synthetic_users = static function ( Env $env, string $fixture = '' ): int {
	$count = 0;
	foreach ( $env->all_users() as $user ) {
		$login = (string) ( $user['login'] ?? '' );
		if ( 0 !== strpos( $login, 'cpsd-' ) ) {
			continue;
		}
		if ( '' !== $fixture && ! str_ends_with( $login, '-' . $fixture ) ) {
			continue;
		}
		++$count;
	}

	return $count;
};

try {
	if ( ! class_exists( ScopedManifest::class ) ) {
		throw new RuntimeException( 'companion plugin inactive' );
	}
	if ( ! in_array( $cpsd_mode, array( 'stage', 'finish' ), true ) ) {
		throw new RuntimeException( 'usage: CPSD_INCIDENT_MODE=stage|finish' );
	}

	wp_set_current_user( 1 );
	$admin       = 1;
	$env         = new Env();
	$platform    = new Platform();
	$manifest    = new ScopedManifest( $env );
	$audit_guard = new ScopedAuditGuard( $platform );
	$baseline    = new ScopedBaseline( $env, $platform );
	$preflight   = new ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );
	$credentials = new Credentials( $env );
	$provisioner = new ScopedProvisioner( $env, $platform, $manifest, $preflight, $credentials, $baseline, $audit_guard );
	$teardown    = new ScopedTeardown( $env, $platform, $manifest, $audit_guard, $baseline );
	$recovery    = new ScopedRecovery( $env, $platform, $manifest );
	$evidence    = array(
		'ok'   => true,
		'mode' => $cpsd_mode,
	);

	if ( 'stage' === $cpsd_mode ) {
		$manifest->enable_mode( $admin );
		$result = $provisioner->provision( $admin );
		$sealed = $manifest->load();
		if ( ! is_array( $sealed ) || 'active' !== (string) ( $sealed['status'] ?? '' ) ) {
			throw new RuntimeException( 'the fixture was not sealed by provisioning' );
		}
		$sealed = (array) $result['manifest'];
		$snapshot = $cpsd_snapshot( $env, $platform, $cpsd_counted_tables );

		// Reproduce the OLD failure handler exactly: write the pre-seal snapshot
		// over the durable record, then drop the only other copy of the baseline.
		$stale                 = $sealed;
		$stale['status']       = 'failed';
		$stale['failed_stage'] = 'seal';
		$stale['failure_code'] = 'PORTAL_POLICY';
		unset( $stale['baseline'], $stale['host_before'], $stale['owned'], $stale['audit_chain_at_seal'], $stale['sealed_at'] );
		$manifest->save( $stale );
		$manifest->clear_pending_baseline();

		$incident = $manifest->load();
		if ( ! is_array( $incident ) ) {
			throw new RuntimeException( 'the incident manifest disappeared' );
		}
		$verdict = $recovery->assess( $incident );
		$preview = $teardown->preview( $incident );
		$state   = $preflight->run();

		$evidence['fixture_id']        = (string) $incident['fixture_id'];
		$evidence['status']            = (string) $incident['status'];
		$evidence['synthetic_users']   = $cpsd_synthetic_users( $env, (string) $incident['fixture_id'] );
		$evidence['synthetic_users_site'] = $cpsd_synthetic_users( $env );
		$evidence['counts']            = $snapshot;
		$evidence['verdict']           = (string) $verdict['verdict'];
		$evidence['verdict_code']      = (string) $verdict['code'];
		$evidence['teardown_blocked']  = (string) $verdict['teardown_blocked'];
		$evidence['teardown_ok']       = (bool) $preview['ok'];
		$evidence['teardown_problems'] = array_values( (array) $preview['problems'] );
		$evidence['preflight_blocking'] = array_values( (array) $state['blocking'] );

		if ( 'failed' !== (string) $incident['status'] ) {
			throw new RuntimeException( 'the incident state does not read failed' );
		}
		if ( array() !== (array) ( $incident['baseline'] ?? array() ) ) {
			throw new RuntimeException( 'the incident state still carries a sealed baseline' );
		}
		if ( $manifest->has_pending_baseline() ) {
			throw new RuntimeException( 'the incident state still has a pending baseline' );
		}
		if ( 8 !== (int) $evidence['synthetic_users'] ) {
			throw new RuntimeException( 'the incident state does not have eight synthetic accounts' );
		}
		if ( true === (bool) $preview['ok'] ) {
			throw new RuntimeException( 'the incident state must not permit a destructive teardown' );
		}
		if ( ! in_array( 'BASELINE_MISSING', (array) $preview['problems'], true ) ) {
			throw new RuntimeException( 'the incident state is not refused with BASELINE_MISSING' );
		}
		if ( ! in_array( 'other_manifest', (array) $state['blocking'], true ) ) {
			throw new RuntimeException( 'the incident state does not block a second fixture' );
		}
		if ( ScopedRecovery::VERDICT_USABLE !== (string) $verdict['verdict'] ) {
			throw new RuntimeException( 'the repaired package must offer the additive activation for this state (' . (string) $verdict['verdict'] . '/' . (string) $verdict['code'] . ')' );
		}
	} else {
		$live = $manifest->load();
		if ( ! is_array( $live ) ) {
			throw new RuntimeException( 'no live fixture: the Chromium phase did not run or did not repair it' );
		}
		$verdict = $recovery->assess( $live );
		$evidence['fixture_id']      = (string) $live['fixture_id'];
		$evidence['status']          = (string) $live['status'];
		$evidence['activation_mode'] = (string) ( $live['activation']['mode'] ?? '' );
		$evidence['verdict']         = (string) $verdict['verdict'];
		$evidence['teardown_blocked'] = (string) $verdict['teardown_blocked'];
		$evidence['synthetic_users'] = $cpsd_synthetic_users( $env, (string) $live['fixture_id'] );
		$evidence['synthetic_users_site'] = $cpsd_synthetic_users( $env );
		$evidence['parked_before']   = count( $manifest->preserved() );

		if ( 'active' !== (string) $live['status'] || 'baseline_unavailable' !== (string) ( $live['activation']['mode'] ?? '' ) ) {
			throw new RuntimeException( 'the one-click repair did not activate the fixture' );
		}
		if ( array() !== (array) ( $live['owned'] ?? array() ) || ! empty( $live['audit_chain_at_seal'] ) ) {
			throw new RuntimeException( 'the activation fabricated seal evidence' );
		}
		if ( ! in_array( ScopedRecovery::VERDICT_USABLE, array( (string) $verdict['verdict'] ), true ) ) {
			throw new RuntimeException( 'the repaired fixture must stay usable-but-unsealed (' . (string) $verdict['verdict'] . ')' );
		}
		if ( 'BASELINE_MISSING' !== (string) $verdict['teardown_blocked'] ) {
			throw new RuntimeException( 'the repaired fixture must keep teardown blocked' );
		}
		// The destructive direction is still refused, before and after any thought
		// of parking: a repair is not a licence to delete.
		$refused = $teardown->preview( $live );
		if ( true === (bool) $refused['ok'] ) {
			throw new RuntimeException( 'the repair must not unlock a destructive teardown' );
		}
		$evidence['teardown_refused'] = in_array( 'BASELINE_MISSING', (array) $refused['problems'], true );

		$before_park = $cpsd_snapshot( $env, $platform, $cpsd_counted_tables );
		$parked_id   = (string) $live['fixture_id'];
		$fresh       = $provisioner->preserve_and_reprovision( $admin );
		$fresh_live  = $manifest->load();
		$after_park  = $cpsd_snapshot( $env, $platform, $cpsd_counted_tables );
		$record      = $manifest->preserved_record( $parked_id );

		$evidence['parked_fixture']   = $parked_id;
		$evidence['fresh_fixture']    = is_array( $fresh_live ) ? (string) $fresh_live['fixture_id'] : '';
		$evidence['fresh_status']     = is_array( $fresh_live ) ? (string) $fresh_live['status'] : '';
		$evidence['parked_manifest_baseline'] = array() !== (array) ( $record['manifest']['baseline'] ?? array() );
		$evidence['parked_users']     = $cpsd_synthetic_users( $env, $parked_id );
		$evidence['synthetic_users_site_after_park'] = $cpsd_synthetic_users( $env );

		if ( ! is_array( $record ) ) {
			throw new RuntimeException( 'the parked fixture was not archived' );
		}
		if ( ! is_array( $fresh_live ) || 'active' !== (string) $fresh_live['status'] ) {
			throw new RuntimeException( 'the fresh demo is not active' );
		}
		if ( (string) $fresh_live['fixture_id'] === $parked_id ) {
			throw new RuntimeException( 'the fresh demo reused the parked fixture identity' );
		}
		// Parking deletes nothing: the parked fixture keeps all eight of its own
		// accounts, and the site gains exactly the fresh fixture's eight.
		if ( 8 !== (int) $evidence['parked_users'] ) {
			throw new RuntimeException( 'parking did not keep the parked fixture\'s eight accounts (' . (string) $evidence['parked_users'] . ')' );
		}
		$site_before_park = (int) $evidence['synthetic_users_site'];
		if ( (int) $evidence['synthetic_users_site_after_park'] !== $site_before_park + 8 ) {
			throw new RuntimeException( 'parking must add exactly the fresh fixture\'s eight accounts (' . $site_before_park . ' -> ' . (string) $evidence['synthetic_users_site_after_park'] . ')' );
		}
		// Parking may only add the fresh fixture's rows; it may never remove one.
		foreach ( $before_park as $short => $count ) {
			if ( (int) ( $after_park[ $short ] ?? 0 ) < (int) $count ) {
				throw new RuntimeException( 'parking removed rows from ' . $short . ': ' . $count . ' -> ' . (string) $after_park[ $short ] );
			}
		}

		$fresh_preview = $teardown->preview( $fresh_live );
		if ( true !== (bool) $fresh_preview['ok'] ) {
			throw new RuntimeException( 'the fresh demo cannot be torn down: ' . implode( ' | ', (array) $fresh_preview['problems'] ) );
		}
		$clean = $teardown->execute( $fresh_live, $admin );
		if ( true !== (bool) $clean['ok'] ) {
			throw new RuntimeException( 'the fresh demo did not tear down cleanly' );
		}
		$after_clean = $cpsd_snapshot( $env, $platform, $cpsd_counted_tables );
		$evidence['fresh_teardown_ok'] = true;
		$evidence['parked_archive_after_teardown'] = count( $manifest->preserved() );
		$evidence['synthetic_users_after'] = $cpsd_synthetic_users( $env, $parked_id );
		$evidence['synthetic_users_site_after'] = $cpsd_synthetic_users( $env );
		$evidence['counts_after_teardown'] = $after_clean;
		$evidence['live_manifest_after_teardown'] = null !== $manifest->load();

		if ( null !== $manifest->load() ) {
			throw new RuntimeException( 'a live manifest survived the fresh teardown' );
		}
		// The parked fixture is the ONLY remaining owner of its rows: eight
		// accounts, its Clinic and everything under it stay exactly where they
		// were, waiting for an explicit human decision - never for a guess.
		if ( 8 !== (int) $evidence['synthetic_users_after'] ) {
			throw new RuntimeException( 'the parked fixture must keep its eight accounts (' . (string) $evidence['synthetic_users_after'] . ')' );
		}
		if ( (int) $after_clean['cpms_clinics'] !== (int) $before_park['cpms_clinics'] ) {
			throw new RuntimeException( 'the parked Clinic must survive the fresh teardown' );
		}
		$evidence['parked_record_intact'] = is_array( $manifest->preserved_record( $parked_id ) );
		if ( true !== $evidence['parked_record_intact'] ) {
			throw new RuntimeException( 'the parked record did not survive' );
		}
		$chain = $audit_guard->verify_chain();
		$evidence['audit_chain_ok'] = (bool) $chain['ok'];
		if ( true !== (bool) $chain['ok'] ) {
			throw new RuntimeException( 'the audit chain is invalid after the repair' );
		}
	}

	echo (string) wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode(
		array(
			'ok'    => false,
			'mode'  => $cpsd_mode,
			'error' => get_class( $error ) . ': ' . $error->getMessage(),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
}
