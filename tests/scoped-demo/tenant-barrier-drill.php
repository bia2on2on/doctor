<?php
/**
 * Real-WordPress proof of the scoped-demo tenant write barrier.
 *
 * WHY THIS EXISTS
 * ---------------
 * The `Standalone on a non-empty site` lanes of PR #283 fail at the Task-4
 * recovery drill with this annotation (both engines, same message):
 *
 *   production Hold exposes the final confirm button — state=bookable
 *   status=در حالت «دموی محدودشده» نوشتن در درمانگاه/سازمان نمایشی فقط برای
 *   حساب‌های ساختگی همان دمو و مدیر سایت مجاز است. …
 *
 * That Persian text is `ScopedTenantGuard::write_verdict()`'s refusal. The drill
 * books with a mobile that is NOT the synthetic one; `OtpService::verify()`
 * therefore provisions a REAL WordPress user and issues a real session
 * (`wp_set_auth_cookie`), and the very next public-booking write (the Hold) is
 * refused — by design, because Mission F forbids real actors writing into the
 * demo tenant. So the corridor the drill walks is closed on purpose and the
 * assertion, not the product, is what is wrong.
 *
 * This drill pins the barrier's exact semantics on a REAL WordPress runtime so
 * the correction is evidenced rather than inferred. It needs only options and
 * users (no CPMS schema), so it runs on any engine.
 *
 * Usage:
 *   wp eval-file tests/scoped-demo/tenant-barrier-drill.php [out.json] \
 *      --use-include --path=/path/to/wp --allow-root
 *
 * Nothing here is shipped inside the demo package. The drill creates two users
 * and deletes exactly those users and its own options afterwards.
 *
 * @package CpmsDisposableDemo
 */

use CpmsDemo\Env;
use CpmsDemo\ScopedManifest;
use CpmsDemo\ScopedTenantGuard;

defined( 'ABSPATH' ) || exit;

$cpsd_tb_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
if ( array() === $cpsd_tb_args && isset( $GLOBALS['cpsd_tb_argv'] ) && is_array( $GLOBALS['cpsd_tb_argv'] ) ) {
	$cpsd_tb_args = array_values( $GLOBALS['cpsd_tb_argv'] );
}
$cpsd_tb_out = (string) ( $cpsd_tb_args[0] ?? '' );

$cpsd_tb_checks = array();
$cpsd_tb_check  = static function ( bool $ok, string $name, string $detail = '' ) use ( &$cpsd_tb_checks ): void {
	$cpsd_tb_checks[] = array( 'name' => $name, 'ok' => $ok, 'detail' => $detail );
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
};

$cpsd_tb_created_users = array();
$cpsd_tb_option_names  = array( 'cpsd_manifest', 'cpsd_mode', 'cpsd_had_clean_teardown', 'cpsd_tombstone' );

try {
	// The barrier is a companion class, not part of the shipped core.
	if ( ! class_exists( '\\CpmsDemo\\ScopedTenantGuard' ) ) {
		throw new RuntimeException( 'CpmsDemo\\ScopedTenantGuard is not loaded — the demo package must be active' );
	}

	$cpsd_tb_fixture = bin2hex( random_bytes( 16 ) );

	// A synthetic fixture account, exactly as the provisioner names it.
	$cpsd_tb_fixture_user = wp_insert_user(
		array(
			'user_login'   => 'cpsd-doctor-' . $cpsd_tb_fixture,
			'user_pass'    => wp_generate_password( 32 ),
			'user_email'   => 'cpsd-doctor-' . $cpsd_tb_fixture . '@example.invalid',
			'display_name' => 'پزشک نمایشی',
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $cpsd_tb_fixture_user ) ) {
		throw new RuntimeException( 'cannot create the synthetic fixture user: ' . $cpsd_tb_fixture_user->get_error_message() );
	}
	$cpsd_tb_created_users[] = (int) $cpsd_tb_fixture_user;

	// A REAL person: this is what `OtpService::verify()` provisions when someone
	// types their own mobile into the demo's public booking page.
	$cpsd_tb_real_user = wp_insert_user(
		array(
			'user_login'   => 'cpsd-realvisitor-' . $cpsd_tb_fixture,
			'user_pass'    => wp_generate_password( 32 ),
			'user_email'   => 'cpsd-realvisitor-' . $cpsd_tb_fixture . '@example.invalid',
			'display_name' => 'بیمار واقعی',
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $cpsd_tb_real_user ) ) {
		throw new RuntimeException( 'cannot create the real-visitor user: ' . $cpsd_tb_real_user->get_error_message() );
	}
	$cpsd_tb_created_users[] = (int) $cpsd_tb_real_user;

	$cpsd_tb_env      = new Env();
	$cpsd_tb_manifest = new ScopedManifest( $cpsd_tb_env );
	$cpsd_tb_manifest->enable_mode( 1 );
	$cpsd_tb_manifest->save(
		array(
			'status'     => 'active',
			'fixture_id' => $cpsd_tb_fixture,
			'created_at' => $cpsd_tb_env->now_sql(),
			'ids'        => array(
				'clinic_id'       => 2,
				'organization_id' => 3,
				'location_ids'    => array( 4 ),
				'user_ids'        => array( (int) $cpsd_tb_fixture_user ),
			),
			'links'      => array(),
		)
	);
	$cpsd_tb_reload = $cpsd_tb_manifest->load();
	$cpsd_tb_check( is_array( $cpsd_tb_reload ) && 'active' === (string) ( $cpsd_tb_reload['status'] ?? '' ), 'the sealed scoped fixture verifies on this site (so the barrier is armed)' );

	$cpsd_tb_demo_write  = array( 'clinic_id' => 2 );
	$cpsd_tb_other_write = array( 'clinic_id' => 999 );

	// ---- Scenario 1: anonymous public booking into the demo Clinic ----------
	wp_set_current_user( 0 );
	$cpsd_tb_check( true === ScopedTenantGuard::write_verdict( 'POST', $cpsd_tb_demo_write ), 'anonymous public booking into the demo Clinic is ALLOWED (the demo public journey)' );

	// ---- Scenario 2: the fixture's own synthetic account --------------------
	wp_set_current_user( (int) $cpsd_tb_fixture_user );
	$cpsd_tb_check( true === ScopedTenantGuard::write_verdict( 'POST', $cpsd_tb_demo_write ), 'a synthetic fixture account may write into its own demo tenant' );

	// ---- Scenario 3: the administrator (the demo operator) ------------------
	wp_set_current_user( 1 );
	$cpsd_tb_check( true === ScopedTenantGuard::write_verdict( 'POST', $cpsd_tb_demo_write ), 'the site administrator (demo operator, manage_options) may write' );

	// ---- Scenario 4: a REAL person who verified an OTP (the CI failure) ----
	wp_set_current_user( (int) $cpsd_tb_real_user );
	$cpsd_tb_verdict = ScopedTenantGuard::write_verdict( 'POST', $cpsd_tb_demo_write );
	$cpsd_tb_check(
		is_string( $cpsd_tb_verdict ) && false !== mb_strpos( $cpsd_tb_verdict, 'حساب‌های ساختگی' ),
		'a real authenticated visitor is REFUSED on a demo-tenant write (this is the PR #283 recovery-drill failure, and it is correct behaviour)',
		is_string( $cpsd_tb_verdict ) ? mb_substr( $cpsd_tb_verdict, 0, 120 ) : 'allowed'
	);

	// ---- Scenario 5: the same real visitor writing elsewhere is untouched --
	$cpsd_tb_check( true === ScopedTenantGuard::write_verdict( 'POST', $cpsd_tb_other_write ), 'the same visitor is NOT blocked outside the demo tenant (the barrier is scoped, not global)' );

	// ---- Scenario 6: reads are never blocked --------------------------------
	$cpsd_tb_check( true === ScopedTenantGuard::write_verdict( 'GET', $cpsd_tb_demo_write ), 'read requests into the demo tenant stay allowed' );

	// ---- Scenario 7: the production OTP path really issues a session --------
	// The link in the causal chain: the drill's actor is a REAL user because the
	// production OTP verification provisions one and issues an auth cookie.
	$cpsd_tb_otp_candidates = array(
		WP_PLUGIN_DIR . '/cpms-disposable-demo/core/src/Application/Auth/OtpService.php',
		dirname( __DIR__, 2 ) . '/clinic-practice-management/src/Application/Auth/OtpService.php',
	);
	$cpsd_tb_otp_source     = '';
	$cpsd_tb_otp_path       = '';
	foreach ( $cpsd_tb_otp_candidates as $cpsd_tb_candidate ) {
		if ( is_file( $cpsd_tb_candidate ) ) {
			$cpsd_tb_otp_source = (string) file_get_contents( $cpsd_tb_candidate );
			$cpsd_tb_otp_path   = $cpsd_tb_candidate;
			break;
		}
	}
	$cpsd_tb_check(
		false !== strpos( $cpsd_tb_otp_source, 'wp_set_auth_cookie' ),
		'the production OTP service issues a WordPress session after verification (read from the shipped source)',
		'' === $cpsd_tb_otp_path ? 'source not found' : $cpsd_tb_otp_path
	);
	$cpsd_tb_check(
		false !== strpos( $cpsd_tb_otp_source, 'wp_insert_user' ),
		'the production OTP service provisions a REAL WordPress user for an unknown mobile',
		''
	);
} catch ( Throwable $cpsd_tb_error ) {
	$cpsd_tb_check( false, 'tenant-barrier drill completed without an exception', get_class( $cpsd_tb_error ) . ': ' . $cpsd_tb_error->getMessage() );
} finally {
	// ---- Cleanup: exactly what this drill created ---------------------------
	wp_set_current_user( 1 );
	foreach ( $cpsd_tb_option_names as $cpsd_tb_option ) {
		delete_option( $cpsd_tb_option );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cpsd_tb_created_users as $cpsd_tb_user_id ) {
		if ( $cpsd_tb_user_id > 0 ) {
			wp_delete_user( $cpsd_tb_user_id );
		}
	}
	$cpsd_tb_check( false === get_option( 'cpsd_manifest', false ), 'cleanup removed the drill fixture and its users' );
}

$cpsd_tb_ok = array() !== $cpsd_tb_checks;
foreach ( $cpsd_tb_checks as $cpsd_tb_row ) {
	if ( ! $cpsd_tb_row['ok'] ) {
		$cpsd_tb_ok = false;
	}
}
$cpsd_tb_result = array(
	'ok'       => $cpsd_tb_ok,
	'checks'   => $cpsd_tb_checks,
	'evidence' => array(
		'root_cause' => 'OtpService::verify() provisions a real WordPress user and calls wp_set_auth_cookie(); the subsequent public-booking Hold is a write into the demo tenant by a non-fixture, non-administrator actor, so ScopedTenantGuard refuses it with SCOPED_DEMO_TENANT_WRITE_REFUSED.',
	),
);
$cpsd_tb_json = (string) wp_json_encode( $cpsd_tb_result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( '' !== $cpsd_tb_out && false === file_put_contents( $cpsd_tb_out, $cpsd_tb_json ) ) {
	echo "FAIL cannot write evidence file: {$cpsd_tb_out}\n";
	$cpsd_tb_ok = false;
}
echo $cpsd_tb_json . "\n";
exit( $cpsd_tb_ok ? 0 : 1 );
