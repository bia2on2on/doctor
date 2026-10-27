<?php
/**
 * Out-of-band tamper / restore of the sealed scoped-demo manifest.
 *
 * This exists so the Chromium lane can prove ONE property no PHP drill can
 * prove from the inside: the scoped admin page, rendering in a real browser,
 * refuses a manifest whose HMAC no longer holds and offers no action at all.
 *
 * Run with (the mode is an env var, like the other wp-cli drivers in this repo
 * that never rely on positional eval-file arguments):
 *   CPSD_TAMPER_MODE=tamper  wp eval-file tests/scoped-demo/tamper-manifest.php ...
 *   CPSD_TAMPER_MODE=restore wp eval-file tests/scoped-demo/tamper-manifest.php ...
 *
 * `tamper` copies the verified payload to CPSD_TAMPER_FILE (default
 * /tmp/cpsd-manifest-pristine.json) and then writes a payload one field away
 * from the sealed one. `restore` writes the saved payload back and re-proves
 * that the fixture is exactly as provisioning left it.
 *
 * It never deletes a row, never mints a session, and prints one JSON line.
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

$cpsd_mode = (string) ( getenv( 'CPSD_TAMPER_MODE' ) ?: 'tamper' );
$cpsd_file = (string) ( getenv( 'CPSD_TAMPER_FILE' ) ?: '/tmp/cpsd-manifest-pristine.json' );
// The scoped manifest option name; ScopedManifest keeps it instance-private.
$cpsd_option = 'cpsd_manifest';

/**
 * Count the synthetic accounts provisioned under the scoped fixture prefix.
 *
 * @param Env $env Environment.
 * @return int
 */
$cpsd_synthetic_users = static function ( Env $env ): int {
	$count = 0;
	foreach ( $env->all_users() as $user ) {
		if ( 0 === strpos( (string) ( $user['login'] ?? '' ), 'cpsd-' ) ) {
			++$count;
		}
	}

	return $count;
};

try {
	if ( ! class_exists( ScopedManifest::class ) ) {
		throw new RuntimeException( 'companion plugin inactive' );
	}
	if ( ! in_array( $cpsd_mode, array( 'tamper', 'restore' ), true ) ) {
		throw new RuntimeException( 'usage: CPSD_TAMPER_MODE=tamper|restore' );
	}

	wp_set_current_user( 1 );
	$env         = new Env();
	$platform    = new Platform();
	$manifest    = new ScopedManifest( $env );
	$audit_guard = new ScopedAuditGuard( $platform );
	$baseline    = new ScopedBaseline( $env, $platform );
	$teardown    = new ScopedTeardown( $env, $platform, $manifest, $audit_guard, $baseline );
	$evidence    = array(
		'ok'               => true,
		'mode'             => $cpsd_mode,
		'file'             => $cpsd_file,
		'synthetic_users'  => $cpsd_synthetic_users( $env ),
	);

	if ( 'tamper' === $cpsd_mode ) {
		$live = $manifest->load();
		if ( ! is_array( $live ) || 'active' !== (string) ( $live['status'] ?? '' ) ) {
			throw new RuntimeException( 'no active scoped fixture to tamper with' );
		}
		if ( false === file_put_contents( $cpsd_file, (string) wp_json_encode( $live ) ) ) {
			throw new RuntimeException( 'cannot write the pristine manifest copy: ' . $cpsd_file );
		}
		$tampered = $live;
		// One persisted identity field moved outside the fixture's own scope.
		// Every id in the sealed payload is HMAC-covered, so this must fail
		// closed in every consumer (load, preview, execute, admin page).
		$tampered['ids']['clinic_id'] = (int) $live['ids']['clinic_id'] + 5000;
		$env->option_set( $cpsd_option, $tampered );

		$refused = false;
		try {
			$manifest->load();
		} catch ( Throwable $error ) {
			$refused = $error instanceof CpmsDemo\Exception && 'MANIFEST_TAMPERED' === $error->demo_code();
		}
		$evidence['fixture_id']    = (string) $live['fixture_id'];
		$evidence['clinic_id']     = (int) $live['ids']['clinic_id'];
		$evidence['tampered_field'] = 'ids.clinic_id';
		$evidence['load_refused']  = $refused;
		if ( ! $refused ) {
			throw new RuntimeException( 'the tampered manifest was NOT refused on load' );
		}
	} else {
		if ( ! is_file( $cpsd_file ) ) {
			throw new RuntimeException( 'no pristine manifest copy at ' . $cpsd_file );
		}
		$pristine = json_decode( (string) file_get_contents( $cpsd_file ), true );
		if ( ! is_array( $pristine ) || ! $manifest->verify( $pristine ) ) {
			throw new RuntimeException( 'the saved manifest copy does not verify' );
		}
		$env->option_set( $cpsd_option, $pristine );
		$restored = $manifest->load();
		if ( ! is_array( $restored ) || ! $manifest->verify( $restored ) ) {
			throw new RuntimeException( 'the restored manifest does not verify' );
		}
		$preview = $teardown->preview( $restored );
		$clinic  = $platform->fetch_row(
			'SELECT id FROM `' . $platform->table( 'cpms_clinics' ) . '` WHERE id = %d',
			array( (int) $restored['ids']['clinic_id'] )
		);
		$evidence['fixture_id']       = (string) $restored['fixture_id'];
		$evidence['status']           = (string) $restored['status'];
		$evidence['preview_ok']       = (bool) $preview['ok'];
		$evidence['preview_problems'] = array_values( (array) $preview['problems'] );
		$evidence['fixture_clinic_present'] = null !== $clinic;
		if ( ! $preview['ok'] ) {
			throw new RuntimeException( 'the restored fixture does not preview clean: ' . implode( ' | ', (array) $preview['problems'] ) );
		}
		if ( null === $clinic ) {
			throw new RuntimeException( 'the restored fixture lost its synthetic Clinic' );
		}
		@unlink( $cpsd_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort temp cleanup.
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
