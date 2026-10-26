<?php
/**
 * Real-WordPress integration acceptance for the demo installer.
 *
 * Executed in CI on a clean WordPress + active CPMS Classic:
 *   wp eval-file tools/disposable-demo-installer/tests/integration-wp.php \
 *     --use-include --path="$WP_DIR" --allow-root --user="$ADMIN_USER"
 *
 * It exercises the *shipping* code paths end to end: preflight, provisioning
 * through production services, six persona logins, the public booking page and
 * shortcode, the real OTP request/verify cycle with the demo code, and the
 * ownership-proofed teardown. It prints evidence only — never credentials,
 * codes, peppers or session data.
 *
 * @package CpmsDisposableDemo
 */

declare(strict_types=1);

use CpmsDemo\Credentials;
use CpmsDemo\Env;
use CpmsDemo\Exception as DemoException;
use CpmsDemo\Manifest;
use CpmsDemo\Otp;
use CpmsDemo\Platform;
use CpmsDemo\Preflight;
use CpmsDemo\Provisioner;
use CpmsDemo\Teardown;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;

$GLOBALS['cpdd_fail'] = 0;

/**
 * @param bool   $ok Pass/fail.
 * @param string $label Evidence line.
 */
function cpdd_assert( bool $ok, string $label ): void {
	if ( ! $ok ) {
		++$GLOBALS['cpdd_fail'];
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
}

$env      = new Env();
$platform = new Platform();
$manifest = new Manifest( $env );
$admin_id = get_current_user_id();

cpdd_assert( $admin_id > 0 && user_can( $admin_id, 'manage_options' ), 'administrator session with manage_options' );
cpdd_assert( class_exists( App::class ), 'CPMS Classic is active in the same request' );
cpdd_assert( '2026_10_18_0029' === $platform->schema_version(), 'CPMS schema is the pinned supported version' );

// --- 1. Preflight ----------------------------------------------------------
$preflight = new Preflight( $env, $platform, $manifest );
$state     = $preflight->run();
if ( ! $state['ok'] ) {
	foreach ( $state['checks'] as $check ) {
		if ( 'pass' !== $check['status'] ) {
			echo 'PREFLIGHT_DETAIL ' . $check['id'] . ' :: ' . $check['detail'] . PHP_EOL;
		}
	}
}
cpdd_assert( true === $state['ok'], 'preflight passes on a clean disposable site' );

// --- 2. Provisioning -------------------------------------------------------
$provisioner = new Provisioner( $env, $platform, $manifest, $preflight, new Credentials( $env ) );
$result      = $provisioner->provision( $admin_id );
$fixture     = $result['manifest'];
$passwords   = $result['credentials'];

cpdd_assert( 'active' === $fixture['status'], 'fixture sealed as active' );
cpdd_assert( 6 === count( $fixture['ids']['user_ids'] ), 'six persona accounts created' );
cpdd_assert( 2 === count( $fixture['ids']['location_ids'] ), 'two locations created' );
cpdd_assert( count( $fixture['ids']['slot_ids'] ) >= 6, 'at least six future public-booking slots' );
cpdd_assert( isset( $fixture['ids']['visit_id'], $fixture['ids']['invoice_id'], $fixture['ids']['payment_id'], $fixture['ids']['follow_up_id'], $fixture['ids']['prescription_id'] ), 'visit, invoice, payment, prescription and follow-up recorded' );

$clinic_id = (int) $fixture['ids']['clinic_id'];
$doctor_id = (int) $fixture['ids']['clinician_id'];
$mobile    = (string) $fixture['patient_mobile'];

// --- 3. Six independent logins -------------------------------------------
foreach ( $passwords as $persona => $password ) {
	$login = 'cpdd-' . $persona . '-' . $fixture['fixture_id'];
	$user  = wp_authenticate( $login, $password );
	cpdd_assert( $user instanceof WP_User, 'login works for persona ' . $persona );
	$id = $user instanceof WP_User ? (int) $user->ID : 0;
	cpdd_assert( $id > 0 && ! user_can( $id, 'manage_options' ), 'persona ' . $persona . ' has no site-administrator authority' );
}
$patient_wp_id = (int) $fixture['ids']['user_ids']['patient'];
$patient_user  = get_userdata( $patient_wp_id );
cpdd_assert( $patient_user instanceof WP_User && in_array( 'cpms_patient', (array) $patient_user->roles, true ), 'patient persona holds the cpms_patient role' );

// --- 4. Public booking page + real shortcode ------------------------------
$page_id  = (int) $fixture['ids']['booking_page_id'];
$page     = get_post( $page_id );
$expected = '[cpms_public_booking clinic_id="' . $clinic_id . '"]';
cpdd_assert( $page instanceof WP_Post && 'publish' === $page->post_status, 'booking page is published' );
cpdd_assert( $page instanceof WP_Post && str_contains( (string) $page->post_content, $expected ), 'booking page carries the real shortcode ' . $expected );
cpdd_assert( shortcode_exists( 'cpms_public_booking' ), 'production shortcode tag is registered' );
$rendered = do_shortcode( $expected );
cpdd_assert( '' !== trim( (string) $rendered ), 'public booking shortcode renders server-side output (bytes=' . strlen( (string) $rendered ) . ')' );

$db    = App::db();
$slots = (int) $db->fetchValue(
	'SELECT COUNT(*) FROM ' . $db->table( 'cpms_schedule_slots' )
	. ' WHERE clinic_id = %d AND clinician_id = %d AND is_open = 1 AND booked_count = 0 AND slot_date >= %s',
	array( $clinic_id, $doctor_id, gmdate( 'Y-m-d' ) )
);
cpdd_assert( $slots >= 6, 'open future slots available for a NEW public booking (count=' . $slots . ')' );

// --- 5. Real OTP request → demo code → real verify ------------------------
$previous = ScopeContext::tryGet();
ScopeContext::set( ClinicScope::forClinic( $clinic_id, (int) $fixture['ids']['location_ids'][0] )->withOrganization( (int) $fixture['ids']['organization_id'] ) );
try {
	App::otpService()->request( $mobile, 'login', null, '127.0.0.1', $clinic_id );
	cpdd_assert( true, 'real OTP request accepted for the synthetic patient' );

	$otp    = new Otp( $env, $platform );
	$issued = $otp->issue( $fixture );
	cpdd_assert( 1 === preg_match( '/^\d{6}$/', (string) $issued['code'] ), 'demo OTP code is six digits' );

	$token_row = $db->fetchRow(
		'SELECT code_hash, attempts, consumed_at, expires_at FROM ' . $db->table( 'cpms_otp_tokens' ) . ' WHERE id = %d',
		array( (int) $issued['token_id'] )
	);
	cpdd_assert( null !== $token_row && ! str_contains( (string) $token_row['code_hash'], (string) $issued['code'] ), 'only the hash is stored; the code is never persisted' );
	cpdd_assert( 0 === (int) $token_row['attempts'] && null === $token_row['consumed_at'], 'attempt counter and consumption state are untouched before verify' );

	add_filter( 'send_auth_cookies', '__return_false' );
	try {
		$verified = App::otpService()->verify( $mobile, (string) $issued['code'], 'login', '127.0.0.1' );
		cpdd_assert( isset( $verified['user_id'] ) || isset( $verified['wp_user_id'] ), 'real verify route accepts the demo code' );
	} catch ( Throwable $e ) {
		cpdd_assert( false, 'real verify route accepts the demo code (' . get_class( $e ) . ')' );
	} finally {
		remove_filter( 'send_auth_cookies', '__return_false' );
	}
	wp_set_current_user( $admin_id );

	$consumed = $db->fetchValue( 'SELECT consumed_at FROM ' . $db->table( 'cpms_otp_tokens' ) . ' WHERE id = %d', array( (int) $issued['token_id'] ) );
	cpdd_assert( null !== $consumed, 'OTP is consumed exactly once by the real route' );

	try {
		App::otpService()->verify( $mobile, (string) $issued['code'], 'login', '127.0.0.1' );
		cpdd_assert( false, 'a consumed code is refused on replay' );
	} catch ( Throwable $e ) {
		cpdd_assert( true, 'a consumed code is refused on replay (' . get_class( $e ) . ')' );
	}

	try {
		$otp->issue( $fixture );
		cpdd_assert( false, 'the demo code cannot be re-minted for a consumed challenge' );
	} catch ( DemoException $e ) {
		cpdd_assert( true, 'the demo code cannot be re-minted for a consumed/expired challenge (' . $e->demo_code() . ')' );
	}

	// Scoping: the demo tool must not expose codes for any other mobile.
	try {
		App::otpService()->request( '09121234567', 'login', null, '127.0.0.1', $clinic_id );
		$otp->issue( $fixture );
		cpdd_assert( false, 'a foreign mobile never receives a demo code' );
	} catch ( DemoException $e ) {
		cpdd_assert( in_array( $e->demo_code(), array( 'OTP_CHALLENGE', 'OTP_SCOPE' ), true ), 'a foreign mobile never receives a demo code (' . $e->demo_code() . ')' );
	}

	// S-3: Foreign rate-limit and operational-log rows from 09121234567 must block teardown!
	$blocked_by_foreign = ( new Teardown( $env, $platform, $manifest ) )->verify( $fixture );
	cpdd_assert( false === $blocked_by_foreign['ok'], 's3: foreign mobile rate-limit and oplog rows block teardown' );

	// Remove the foreign 09121234567 test rows so only fixture-owned rows remain.
	$db->execute( $db->prepare( 'DELETE FROM ' . $db->table( 'cpms_rate_limits' ) . ' WHERE window_key IN (%s, %s)', array( 'otp-day:09121234567', 'otp-hour:09121234567' ) ) );
	$db->execute( $db->prepare( 'DELETE FROM ' . $db->table( 'cpms_operational_logs' ) . " WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.to')) = %s", array( '09121234567' ) ) );
	$foreign_sms_ids = $db->fetchAll( $db->prepare( 'SELECT id FROM ' . $db->table( 'cpms_sms_messages' ) . ' WHERE recipient_mobile = %s', array( '09121234567' ) ) );
	foreach ( $foreign_sms_ids as $fsms ) {
		$fid = (int) $fsms['id'];
		$db->execute( $db->prepare( 'DELETE FROM ' . $db->table( 'cpms_jobs' ) . " WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.message_id')) AS UNSIGNED) = %d", array( $fid ) ) );
		$db->execute( $db->prepare( 'DELETE FROM ' . $db->table( 'cpms_operational_logs' ) . " WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.message_id')) AS UNSIGNED) = %d", array( $fid ) ) );
	}
	$db->execute( $db->prepare( 'DELETE FROM ' . $db->table( 'cpms_sms_messages' ) . ' WHERE recipient_mobile = %s', array( '09121234567' ) ) );
	$db->execute( $db->prepare( 'DELETE FROM ' . $db->table( 'cpms_otp_tokens' ) . ' WHERE mobile = %s', array( '09121234567' ) ) );
} finally {
	App::replaceExplicitScope( $previous );
}

// --- 5a. S-6 Rotation + S-1 Safe Uninstall while active -------------------
$rotator           = new Credentials( $env );
$rotated_Following = $rotator->rotate( $fixture );
cpdd_assert( 6 === count( $rotated_Following ), 's6: rotation produces six fresh persona secrets' );
foreach ( $rotated_Following as $persona => $new_secret ) {
	$login    = 'cpdd-' . $persona . '-' . $fixture['fixture_id'];
	$old_user = wp_authenticate( $login, $passwords[ $persona ] );
	$new_user = wp_authenticate( $login, $new_secret );
	cpdd_assert( ! ( $old_user instanceof WP_User ) && ( $new_user instanceof WP_User ), 's6: rotated secret authenticates ' . $persona . ' and invalidates previous secret' );
}
$passwords = $rotated_Following;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
require CPMS_DEMO_DIR . 'uninstall.php';
cpdd_assert( is_array( get_option( 'cpdd_manifest', null ) ), 's1: uninstall with active fixture preserves cpdd_manifest in WordPress DB' );
cpdd_assert( is_array( get_option( 'cpdd_uninstall_blocked', null ) ), 's1: uninstall with active fixture records cpdd_uninstall_blocked marker' );

// --- 5b. Admin page RTL render + no stored credentials -------------------
\CpmsDemo\Admin::reset_state();
ob_start();
\CpmsDemo\Admin::render();
$admin_html = (string) ob_get_clean();
cpdd_assert( str_contains( $admin_html, 'data-cpdd-uninstall-blocked="1"' ), 's1: admin page displays recovery notice after blocked uninstall' );
cpdd_assert( str_contains( $admin_html, 'class="wrap cpdd" dir="rtl"' ), 'admin page renders in Persian RTL container' );
cpdd_assert( str_contains( $admin_html, 'پیوندها و نقشهٔ نقش ← صفحه' ), 'admin page renders role-to-page mapping table' );
cpdd_assert( str_contains( $admin_html, 'دستورالعمل فارسی' ), 'admin page renders Persian step-by-step instructions' );
cpdd_assert( str_contains( $admin_html, 'ROTATE_DEMO_PASSWORDS' ) && str_contains( $admin_html, 'DESTROY_DEMO_DATA_ONLY' ), 'admin page renders confirmation-phrase forms' );
$links_map         = (array) ( $fixture['links'] ?? array() );
$all_links_present = true;
foreach ( array( 'booking', 'patient', 'management', 'doctor', 'receptionist', 'accountant' ) as $link_key ) {
	$target_href = (string) ( $links_map[ $link_key ] ?? '' );
	if ( '' === $target_href || ! str_contains( $admin_html, esc_url( $target_href ) ) ) {
		$all_links_present = false;
	}
}
cpdd_assert( $all_links_present, 'admin page renders clickable booking and persona portal links' );
$css = (string) file_get_contents( CPMS_DEMO_DIR . 'assets/admin.css' );
cpdd_assert( str_contains( $css, 'direction: rtl' ) && str_contains( $css, '@media (max-width: 782px)' ) && str_contains( $css, '@media (max-width: 420px)' ), 'admin stylesheet enforces RTL and 320px/390px mobile responsive breakpoints' );
$leaked_pw = false;
foreach ( $passwords as $pw ) {
	if ( '' !== $pw && str_contains( $admin_html, (string) $pw ) ) {
		$leaked_pw = true;
	}
}
cpdd_assert( ! $leaked_pw, 'subsequent admin page render omits one-time persona secrets' );

// --- 6. Ownership-proofed teardown (+ S-2 fault injection & recovery) ----
$teardown = new Teardown( $env, $platform, $manifest );
$plan     = $teardown->verify( $fixture );
if ( ! $plan['ok'] ) {
	foreach ( $plan['problems'] as $problem ) {
		echo 'TEARDOWN_PROBLEM ' . $problem . PHP_EOL;
	}
}
cpdd_assert( true === $plan['ok'], 'ownership proof passes before deletion (tables with fixture rows=' . count( $plan['plan'] ) . ')' );

// S-2: Inject mid-teardown fault at delete_where:cpms_visits and verify manifest preservation + retry recovery.
$env->set_fault_injector(
	static function ( string $point ): void {
		if ( 'delete_where:cpms_visits' === $point ) {
			throw new DemoException( 'SQL_DELETE_FAILED', 'Simulated mid-teardown SQL failure on cpms_visits' );
		}
	}
);
try {
	$teardown->execute( $fixture, $admin_id );
	cpdd_assert( false, 's2: mid-teardown fault fails closed' );
} catch ( DemoException $e ) {
	cpdd_assert( 'SQL_DELETE_FAILED' === $e->demo_code(), 's2: mid-teardown fault fails closed (' . $e->demo_code() . ')' );
}
$env->set_fault_injector( null );
$interrupted_manifest = $manifest->load();
cpdd_assert( is_array( $interrupted_manifest ) && 'teardown_failed' === ( $interrupted_manifest['status'] ?? '' ), 's2: cpdd_manifest preserved with teardown_failed status after partial deletion' );

$cleanup = $teardown->execute( $interrupted_manifest, $admin_id );
cpdd_assert( true === $cleanup['ok'], 'teardown verifies a clean result after recovery retry (deleted tables=' . count( $cleanup['deleted'] ) . ', remaining=' . count( $cleanup['remaining'] ) . ')' );
foreach ( $cleanup['remaining'] as $item ) {
	echo 'REMAINING ' . $item . PHP_EOL;
}

cpdd_assert( false === get_option( 'cpdd_manifest', false ), 'manifest option removed after verified cleanup' );
cpdd_assert( false === get_option( 'cpdd_uninstall_blocked', false ), 's1: cpdd_uninstall_blocked cleared after verified cleanup' );
$personas_gone = true;
foreach ( (array) $fixture['ids']['user_ids'] as $persona_uid ) {
	if ( false !== get_userdata( (int) $persona_uid ) ) {
		$personas_gone = false;
	}
}
cpdd_assert( $personas_gone, 'demo personas removed' );
$owner = get_userdata( $admin_id );
cpdd_assert( $owner instanceof WP_User && user_can( $admin_id, 'manage_options' ), 'Owner Administrator account and session authority preserved' );
$default_clinic = $db->fetchRow( 'SELECT id, slug FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = 1' );
cpdd_assert( null !== $default_clinic && 'default' === (string) $default_clinic['slug'], 'default CPMS bootstrap clinic untouched' );
$leftover = (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_patients' ) . ' WHERE clinic_id = %d', array( $clinic_id ) );
cpdd_assert( 0 === $leftover, 'no synthetic patients remain in the fixture clinic scope' );
$post_state = ( new Preflight( $env, $platform, $manifest ) )->run();
cpdd_assert( true === $post_state['ok'], 'preflight passes again after verified teardown' );

// --- 7. S-4 Partial provisioning recovery on real WordPress + MySQL ------
$env->set_fault_injector(
	static function ( string $point ): void {
		if ( 'provision:organization' === $point ) {
			throw new DemoException( 'INJECTED_PARTIAL', 'Simulated failure before clinic creation' );
		}
	}
);
try {
	$provisioner->provision( $admin_id );
	cpdd_assert( false, 's4: partial provisioning fault stops before clinic creation' );
} catch ( DemoException $e ) {
	cpdd_assert( 'INJECTED_PARTIAL' === $e->demo_code(), 's4: partial provisioning fault stops before clinic creation' );
}
$env->set_fault_injector( null );
$partial_manifest = $manifest->load();
cpdd_assert( is_array( $partial_manifest ) && 'failed' === ( $partial_manifest['status'] ?? '' ), 's4: partial manifest preserved with failed status' );
$partial_cleanup = $teardown->execute( $partial_manifest, $admin_id );
cpdd_assert( true === $partial_cleanup['ok'], 's4: teardown recovers partial provisioning cleanly on real MySQL' );
cpdd_assert( true === ( new Preflight( $env, $platform, $manifest ) )->run()['ok'], 's4: preflight passes after partial provisioning recovery' );

// --- 8. S-5 Genuine concurrent MySQL connection contention ---------------
$host_parts = explode( ':', (string) DB_HOST, 2 );
$db_host    = $host_parts[0];
$db_port    = isset( $host_parts[1] ) ? (int) $host_parts[1] : 3306;
$conn2      = mysqli_init();
if ( $conn2 instanceof mysqli && @$conn2->real_connect( $db_host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $db_port ) ) {
	$stmt = $conn2->prepare( 'SELECT GET_LOCK(?, 0)' );
	$ln   = $env->lock_name();
	$stmt->bind_param( 's', $ln );
	$stmt->execute();
	$stmt->close();
	try {
		$provisioner->provision( $admin_id );
		cpdd_assert( false, 's5: concurrent provisioning across separate MySQL connections is blocked' );
	} catch ( DemoException $e ) {
		cpdd_assert( 'CONCURRENT_OPERATION' === $e->demo_code(), 's5: concurrent provisioning across separate MySQL connections is blocked (' . $e->demo_code() . ')' );
	} finally {
		$rel = $conn2->prepare( 'SELECT RELEASE_LOCK(?)' );
		$rel->bind_param( 's', $ln );
		$rel->execute();
		$rel->close();
		$conn2->close();
	}
	cpdd_assert( false === get_option( 'cpdd_manifest', false ), 's5: blocked concurrent provisioning left zero untracked manifest state' );
}

// Clean uninstall after teardown removes tombstone.
require CPMS_DEMO_DIR . 'uninstall.php';
cpdd_assert( false === get_option( 'cpdd_tombstone', false ), 's1: uninstall after verified teardown removes cpdd_tombstone' );

echo 'CPDD_INTEGRATION=' . ( 0 === (int) $GLOBALS['cpdd_fail'] ? 'PASS' : 'FAIL' ) . ' failures=' . (int) $GLOBALS['cpdd_fail'] . PHP_EOL;
if ( (int) $GLOBALS['cpdd_fail'] > 0 ) {
	exit( 1 );
}
