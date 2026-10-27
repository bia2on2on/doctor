<?php
/**
 * Automated tests for the disposable demo installer.
 *
 * Run: php tools/disposable-demo-installer/tests/run-tests.php
 * (also executed in CI, and locally in this branch via PHP 8.5 wasm).
 *
 * Coverage: authorization/nonce/confirmation/site-identity, every preflight
 * refusal, manifest tamper + site transplant detection, credential generation
 * and rotation scope, OTP guard rails, provisioning idempotency and fixture
 * shape, and teardown refusal/isolation/cleanup.
 *
 * @package CpmsDisposableDemo
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CpmsDemo\Admin;
use CpmsDemo\Credentials;
use CpmsDemo\Env;
use CpmsDemo\Exception as DemoException;
use CpmsDemo\Manifest;
use CpmsDemo\Otp;
use CpmsDemo\Platform;
use CpmsDemo\Preflight;
use CpmsDemo\Provisioner;
use CpmsDemo\Request;
use CpmsDemo\Teardown;

/**
 * @return array{env:Cpdd_Fake_Env,platform:Cpdd_Fake_Platform,manifest:Manifest}
 */
function cpdd_fixture(): array {
	$env      = new Cpdd_Fake_Env();
	$platform = new Cpdd_Fake_Platform();
	$env->seed_clean_site();
	$platform->seed_clean_install();
	$manifest = new Manifest( $env );

	return array(
		'env'      => $env,
		'platform' => $platform,
		'manifest' => $manifest,
	);
}

/**
 * @param array{env:Cpdd_Fake_Env,platform:Cpdd_Fake_Platform,manifest:Manifest} $ctx Context.
 * @param array<string,mixed> $overrides Overrides.
 * @return array<string,mixed>
 */
function cpdd_post( array $ctx, string $action, array $overrides = array() ): array {
	$request = new Request( $ctx['env'], $ctx['manifest'] );
	$post    = array(
		'cpdd_nonce'   => $request->nonce( $action ),
		'cpdd_confirm' => $request->phrase( $action ),
		'cpdd_site'    => $ctx['manifest']->site_fingerprint(),
	);

	return array_merge( $post, $overrides );
}

/**
 * @param array{env:Cpdd_Fake_Env,platform:Cpdd_Fake_Platform,manifest:Manifest} $ctx Context.
 * @return array<string,mixed>
 */
function cpdd_provision( array $ctx ): array {
	$platform    = $ctx['platform'];
	$preflight   = new Preflight( $ctx['env'], $platform, $ctx['manifest'] );
	$provisioner = new Provisioner( $ctx['env'], $platform, $ctx['manifest'], $preflight, new Credentials( $ctx['env'] ) );

	return $provisioner->provision( 1 );
}

// ---------------------------------------------------------------------------
// 0. Menu fallback when CPMS Classic is not loaded in this request
// ---------------------------------------------------------------------------
$GLOBALS['cpdd_test_management_pages'] = array();
$GLOBALS['cpdd_test_hooks']           = array();
Admin::register_menu();
$registered_page = $GLOBALS['cpdd_test_management_pages'][0] ?? array();
Cpdd_Test::ok(
	'cpms-disposable-demo' === ( $registered_page['menu_slug'] ?? '' )
	&& 'manage_options' === ( $registered_page['capability'] ?? '' )
	&& array( Admin::class, 'render' ) === ( $registered_page['callback'] ?? null ),
	'menu fallback: CPMS-inactive page remains a manage_options Tools page with the existing slug and render callback'
);
Cpdd_Test::ok(
	! function_exists( 'add_menu_page' )
	&& in_array( 'load-tools_page_cpms-disposable-demo', array_column( $GLOBALS['cpdd_test_hooks'], 'hook' ), true ),
	'menu fallback: no top-level menu is created and the existing dispatch hook is retained'
);

// ---------------------------------------------------------------------------
// 1. Preflight
// ---------------------------------------------------------------------------
$ctx = cpdd_fixture();
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( true === $state['ok'], 'preflight: clean disposable site passes' );

$ctx = cpdd_fixture();
$ctx['env']->create_user(
	array(
		'user_login'   => 'leftover_staff',
		'user_pass'    => 'x',
		'user_email'   => 'leftover@example.test',
		'display_name' => 'leftover',
		'role'         => 'subscriber',
	)
);
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'users', $state['blocking'], true ), 'preflight: additional user blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['env']->insert_page(
	array(
		'title'   => 'About us',
		'slug'    => 'about',
		'content' => 'real content',
		'author'  => 1,
	)
);
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'content', $state['blocking'], true ), 'preflight: non-default WordPress content blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['env']->insert_page(
	array(
		'title'   => 'Patient portal',
		'slug'    => 'cpms-patient-portal',
		'content' => '',
		'author'  => 1,
	)
);
Cpdd_Test::ok(
	in_array( 'content', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'preflight: an unregistered portal-slug page is still unexpected content'
);
$portal_id = max( array_keys( $ctx['env']->posts ) );
$ctx['env']->options['cpms_patient_portal_page_id'] = $portal_id;
Cpdd_Test::ok(
	true === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'],
	'preflight: the CPMS-registered portal page is accepted as bootstrap content'
);

$ctx = cpdd_fixture();
$ctx['env']->posts[ 90 ] = array(
	'id'      => 90,
	'type'    => 'wp_navigation',
	'status'  => 'publish',
	'slug'    => 'navigation',
	'content' => '',
	'author'  => 0,
);
Cpdd_Test::ok(
	true === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'],
	'preflight: block-theme infrastructure post types are ignored'
);

$ctx = cpdd_fixture();
$ctx['env']->posts[ 91 ] = array(
	'id'      => 91,
	'type'    => 'attachment',
	'status'  => 'inherit',
	'slug'    => 'x-ray',
	'content' => '',
	'author'  => 1,
);
Cpdd_Test::ok(
	in_array( 'content', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'preflight: an attachment blocks provisioning'
);

$ctx = cpdd_fixture();
$ctx['env']->posts[ 92 ] = array(
	'id'      => 92,
	'type'    => 'post',
	'status'  => 'auto-draft',
	'slug'    => '',
	'content' => '',
	'author'  => 1,
);
Cpdd_Test::ok(
	true === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'],
	'preflight: WordPress Dashboard Quick Draft auto-draft placeholder does not block provisioning'
);
$ctx['env']->posts[ 92 ]['status'] = 'draft';
Cpdd_Test::ok(
	in_array( 'content', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'preflight: a user-saved draft post blocks provisioning'
);

$ctx = cpdd_fixture();
$ctx['env']->plugin_list[] = 'woocommerce/woocommerce.php';
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'plugins', $state['blocking'], true ), 'preflight: another active plugin blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['env']->multisite = true;
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'single_site', $state['blocking'], true ), 'preflight: multisite blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['env']->comments = 2;
Cpdd_Test::ok(
	in_array( 'content', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'preflight: foreign comments block provisioning'
);

$ctx = cpdd_fixture();
$ctx['platform']->tables_data['cpms_patients'][] = array(
	'id'        => 9,
	'clinic_id' => 1,
	'mobile'    => '09120000000',
);
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'cpms_data', $state['blocking'], true ), 'preflight: existing clinical record blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['platform']->tables_data['cpms_settings'][] = array(
	'clinic_id'             => 1,
	'key'                   => 'sms.provider',
	'value_json'            => '"generic_api"',
	'updated_by_wp_user_id' => 1,
);
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'cpms_data', $state['blocking'], true ), 'preflight: non-heartbeat CPMS setting blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['platform']->schema = '2026_01_01_0001';
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'cpms', $state['blocking'], true ), 'preflight: unsupported schema version blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['platform']->available_flag = false;
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'cpms', $state['blocking'], true ), 'preflight: inactive CPMS blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['env']->caps[1] = array( 'manage_options' => true );
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'administrator', $state['blocking'], true ), 'preflight: missing install_plugins blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['env']->object_cache = true;
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'object_cache', $state['blocking'], true ), 'preflight: persistent object cache blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['manifest']->save( array( 'fixture_id' => 'abcdef0123456789', 'status' => 'active' ) );
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'manifest', $state['blocking'], true ), 'preflight: existing demo manifest blocks a second provisioning' );

// ---------------------------------------------------------------------------
// 2. Request gate
// ---------------------------------------------------------------------------
$ctx     = cpdd_fixture();
$request = new Request( $ctx['env'], $ctx['manifest'] );
$request->authorize( Request::ACTION_PROVISION, cpdd_post( $ctx, Request::ACTION_PROVISION ) );
Cpdd_Test::ok( true, 'request: valid authorization passes' );

$ctx = cpdd_fixture();
$ctx['env']->caps[1] = array( 'manage_options' => true );
Cpdd_Test::refuses(
	static fn () => ( new Request( $ctx['env'], $ctx['manifest'] ) )->authorize( Request::ACTION_PROVISION, cpdd_post( $ctx, Request::ACTION_PROVISION ) ),
	'CAPABILITY',
	'request: missing install_plugins is refused'
);

$ctx = cpdd_fixture();
Cpdd_Test::refuses(
	static fn () => ( new Request( $ctx['env'], $ctx['manifest'] ) )->authorize( Request::ACTION_PROVISION, cpdd_post( $ctx, Request::ACTION_PROVISION, array( 'cpdd_nonce' => 'forged' ) ) ),
	'NONCE',
	'request: forged nonce is refused'
);

$ctx = cpdd_fixture();
Cpdd_Test::refuses(
	static fn () => ( new Request( $ctx['env'], $ctx['manifest'] ) )->authorize( Request::ACTION_PROVISION, cpdd_post( $ctx, Request::ACTION_PROVISION, array( 'cpdd_confirm' => 'yes' ) ) ),
	'CONFIRMATION',
	'request: wrong confirmation phrase is refused'
);

$ctx = cpdd_fixture();
Cpdd_Test::refuses(
	static fn () => ( new Request( $ctx['env'], $ctx['manifest'] ) )->authorize( Request::ACTION_PROVISION, cpdd_post( $ctx, Request::ACTION_PROVISION, array( 'cpdd_site' => 'another-site' ) ) ),
	'SITE_IDENTITY',
	'request: foreign site fingerprint is refused'
);

$ctx = cpdd_fixture();
$ctx['env']->current_user = 0;
Cpdd_Test::refuses(
	static fn () => ( new Request( $ctx['env'], $ctx['manifest'] ) )->authorize( Request::ACTION_PROVISION, cpdd_post( $ctx, Request::ACTION_PROVISION ) ),
	'AUTH_REQUIRED',
	'request: anonymous caller is refused'
);

$ctx = cpdd_fixture();
Cpdd_Test::refuses(
	static fn () => ( new Request( $ctx['env'], $ctx['manifest'] ) )->authorize( Request::ACTION_TEARDOWN, cpdd_post( $ctx, Request::ACTION_PROVISION ) ),
	'NONCE',
	'request: a nonce for another action cannot be replayed'
);

// ---------------------------------------------------------------------------
// 3. Manifest integrity
// ---------------------------------------------------------------------------
$ctx      = cpdd_fixture();
$manifest = $ctx['manifest'];
$saved    = $manifest->save(
	array(
		'fixture_id' => 'abcdef0123456789',
		'status'     => 'active',
		'ids'        => array(
			'clinic_id'       => 2,
			'organization_id' => 2,
		),
	)
);
Cpdd_Test::ok( is_array( $manifest->load() ), 'manifest: sealed manifest loads and verifies' );
Cpdd_Test::ok( ! isset( $saved['credentials'] ) && ! str_contains( json_encode( $saved ), 'password' ), 'manifest: contains no credential material' );

$ctx   = cpdd_fixture();
$ctx['manifest']->save( array( 'fixture_id' => 'abcdef0123456789', 'status' => 'active', 'ids' => array( 'clinic_id' => 2 ) ) );
$raw                                = $ctx['env']->option_get( Manifest::OPTION );
$raw['ids']['clinic_id']            = 3;
$ctx['env']->option_set( Manifest::OPTION, $raw );
Cpdd_Test::refuses( static fn () => $ctx['manifest']->load(), 'MANIFEST_TAMPERED', 'manifest: edited object id is detected' );

$ctx = cpdd_fixture();
$ctx['manifest']->save( array( 'fixture_id' => 'abcdef0123456789', 'status' => 'active', 'ids' => array( 'clinic_id' => 2 ) ) );
$ctx['env']->home       = 'https://other-site.test/';
$ctx['env']->nonces     = array();
$ctx['env']->now        = 1780000001;
Cpdd_Test::refuses( static fn () => $ctx['manifest']->load(), 'MANIFEST_SITE_MISMATCH', 'manifest: transplanted to another site is refused' );

$ctx = cpdd_fixture();
$ctx['manifest']->save( array( 'fixture_id' => 'abcdef0123456789', 'status' => 'active' ) );
$forged                                      = $ctx['env']->option_get( Manifest::OPTION );
$forged['status']                            = 'torn_down';
$ctx['env']->option_set( Manifest::OPTION, $forged );
Cpdd_Test::refuses( static fn () => $ctx['manifest']->load(), 'MANIFEST_TAMPERED', 'manifest: status rewrite invalidates the MAC' );

// ---------------------------------------------------------------------------
// 4. Credentials
// ---------------------------------------------------------------------------
$ctx         = cpdd_fixture();
$credentials = new Credentials( $ctx['env'] );
$passwords   = $credentials->generate_all();
Cpdd_Test::equals( 6, count( $passwords ), 'credentials: one password per persona' );
Cpdd_Test::equals( 6, count( array_unique( array_values( $passwords ) ) ), 'credentials: all passwords are distinct' );
$shortest = 100;
foreach ( $passwords as $password ) {
	$shortest = min( $shortest, strlen( $password ) );
}
Cpdd_Test::ok( $shortest >= 30, 'credentials: every password is at least 30 chars (192-bit CSPRNG)' );
Cpdd_Test::ok( array() === $ctx['env']->options, 'credentials: generation persists nothing' );

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['env']->users[ $post['manifest']['ids']['user_ids']['doctor'] ]['roles'] = array( 'administrator' );
Cpdd_Test::refuses( static fn () => ( new Credentials( $ctx['env'] ) )->rotate( $post['manifest'] ), 'ROTATE_SCOPE', 'credentials: rotation refuses an account holding administrator' );

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['env']->users[ $post['manifest']['ids']['user_ids']['clinic_manager'] ]['login'] = 'someone_else';
Cpdd_Test::refuses( static fn () => ( new Credentials( $ctx['env'] ) )->rotate( $post['manifest'] ), 'ROTATE_SCOPE', 'credentials: rotation refuses a login outside the fixture' );

$ctx    = cpdd_fixture();
$post   = cpdd_provision( $ctx );
$before = $ctx['env']->passwords;
$new    = ( new Credentials( $ctx['env'] ) )->rotate( $post['manifest'] );
Cpdd_Test::equals( 6, count( $new ), 'credentials: rotation returns six new passwords' );
Cpdd_Test::ok( $before[ $post['manifest']['ids']['user_ids']['doctor'] ] !== $new['doctor'], 'credentials: rotation actually changes the stored hash' );
Cpdd_Test::ok( 0 === $ctx['env']->sessions[ $post['manifest']['ids']['user_ids']['doctor'] ], 'credentials: rotation invalidates existing sessions' );

// ---------------------------------------------------------------------------
// 5. Provisioning
// ---------------------------------------------------------------------------
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
Cpdd_Test::equals( 'active', $post['manifest']['status'], 'provisioning: manifest is sealed as active' );
Cpdd_Test::equals( 6, count( $post['manifest']['ids']['user_ids'] ), 'provisioning: six personas created' );
Cpdd_Test::equals( 6, count( $post['credentials'] ), 'provisioning: six independent credentials returned' );
Cpdd_Test::equals( 2, count( $post['manifest']['ids']['location_ids'] ), 'provisioning: two locations created' );
Cpdd_Test::ok( count( $post['manifest']['ids']['slot_ids'] ) >= 6, 'provisioning: enough future public-booking slots' );
$expected_shortcode = '[cpms_public_booking clinic_id="' . (int) $post['manifest']['ids']['clinic_id'] . '"]';
Cpdd_Test::equals( $expected_shortcode, $post['manifest']['shortcode'], 'provisioning: page uses the actual production shortcode' );
$page = $ctx['env']->post( (int) $post['manifest']['ids']['booking_page_id'] );
Cpdd_Test::ok( null !== $page && str_contains( $page['content'], $expected_shortcode ), 'provisioning: booking page content carries the shortcode' );
Cpdd_Test::equals( 'log', $ctx['platform']->settings['sms.provider'], 'provisioning: offline SMS provider only' );
Cpdd_Test::ok( in_array( 'transition:check_out', $ctx['platform']->calls, true ), 'provisioning: full clinical → finance journey executed' );
Cpdd_Test::ok( in_array( 'create_prescription', $ctx['platform']->calls, true ) && in_array( 'add_follow_up', $ctx['platform']->calls, true ), 'provisioning: prescription and follow-up created' );

$patient_id = (int) $post['manifest']['ids']['user_ids']['patient'];
Cpdd_Test::ok( in_array( 'cpms_patient', $ctx['env']->users[ $patient_id ]['roles'], true ), 'provisioning: patient persona uses the CPMS patient role' );
foreach ( array( 'clinic_manager', 'doctor', 'receptionist', 'accountant', 'organization_manager' ) as $persona ) {
	$id = (int) $post['manifest']['ids']['user_ids'][ $persona ];
	Cpdd_Test::ok( ! in_array( 'administrator', $ctx['env']->users[ $id ]['roles'], true ), 'provisioning: persona ' . $persona . ' is not an Administrator' );
}

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
Cpdd_Test::refuses( static fn () => cpdd_provision( $ctx ), 'ALREADY_PROVISIONED', 'provisioning: second run is idempotent-refused' );

$ctx  = cpdd_fixture();
$ctx['platform']->tables_data['cpms_appointments'][] = array( 'id' => 5, 'clinic_id' => 1 );
Cpdd_Test::refuses( static fn () => cpdd_provision( $ctx ), 'PREFLIGHT', 'provisioning: refuses to start on a non-empty site' );

// ---------------------------------------------------------------------------
// 6. Demo OTP
// ---------------------------------------------------------------------------
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$otp  = new Otp( $ctx['env'], $ctx['platform'] );
Cpdd_Test::refuses( static fn () => $otp->issue( $post['manifest'] ), 'OTP_CHALLENGE', 'otp: no live challenge is refused' );

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$otp  = new Otp( $ctx['env'], $ctx['platform'] );
$ctx['platform']->tables_data['cpms_otp_tokens'][] = array(
	'id'          => 1,
	'clinic_id'   => (int) $post['manifest']['ids']['clinic_id'],
	'mobile'      => $post['manifest']['patient_mobile'],
	'purpose'     => 'login',
	'consumed_at' => null,
	'attempts'    => 0,
	'expires_at'  => gmdate( 'Y-m-d H:i:s', $ctx['env']->now + 300 ),
	'code_hash'   => 'old-hash',
);
$issued = $otp->issue( $post['manifest'] );
Cpdd_Test::equals( '123456', $issued['code'], 'otp: demo code is issued for the live challenge' );
$stored = $ctx['platform']->tables_data['cpms_otp_tokens'][0];
Cpdd_Test::ok( ! str_contains( json_encode( $stored ), '123456' ), 'otp: the plaintext code is never stored' );
Cpdd_Test::ok( 'old-hash' !== $stored['code_hash'], 'otp: only the hash is rebound' );
Cpdd_Test::equals( 0, (int) $stored['attempts'], 'otp: attempt counter is untouched' );
Cpdd_Test::equals( null, $stored['consumed_at'], 'otp: consumption state is untouched' );

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$otp  = new Otp( $ctx['env'], $ctx['platform'] );
$ctx['platform']->tables_data['cpms_otp_tokens'][] = array(
	'id'          => 1,
	'clinic_id'   => (int) $post['manifest']['ids']['clinic_id'],
	'mobile'      => $post['manifest']['patient_mobile'],
	'purpose'     => 'login',
	'consumed_at' => null,
	'attempts'    => 2,
	'expires_at'  => gmdate( 'Y-m-d H:i:s', $ctx['env']->now + 300 ),
	'code_hash'   => 'old-hash',
);
Cpdd_Test::refuses( static fn () => $otp->issue( $post['manifest'] ), 'OTP_CHALLENGE', 'otp: a challenge with failed attempts is refused (limits preserved)' );

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$otp  = new Otp( $ctx['env'], $ctx['platform'] );
$ctx['platform']->tables_data['cpms_otp_tokens'][] = array(
	'id'          => 1,
	'clinic_id'   => (int) $post['manifest']['ids']['clinic_id'],
	'mobile'      => $post['manifest']['patient_mobile'],
	'purpose'     => 'login',
	'consumed_at' => null,
	'attempts'    => 0,
	'expires_at'  => gmdate( 'Y-m-d H:i:s', $ctx['env']->now - 1 ),
	'code_hash'   => 'old-hash',
);
Cpdd_Test::refuses( static fn () => $otp->issue( $post['manifest'] ), 'OTP_CHALLENGE', 'otp: an expired challenge is refused' );

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$otp  = new Otp( $ctx['env'], $ctx['platform'] );
$ctx['platform']->settings['sms.provider'] = 'generic_api';
Cpdd_Test::refuses( static fn () => $otp->issue( $post['manifest'] ), 'OTP_SCOPE', 'otp: a non-log SMS provider is refused (no real SMS)' );

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$otp  = new Otp( $ctx['env'], $ctx['platform'] );
$ctx['platform']->tables_data['cpms_patients'][] = array(
	'id'        => 77,
	'clinic_id' => 1,
	'mobile'    => $post['manifest']['patient_mobile'],
);
Cpdd_Test::refuses( static fn () => $otp->issue( $post['manifest'] ), 'OTP_SCOPE', 'otp: the synthetic mobile existing outside the fixture clinic is refused' );

// ---------------------------------------------------------------------------
// 7. Teardown
// ---------------------------------------------------------------------------
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$plan = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->verify( $post['manifest'], 1 );
Cpdd_Test::ok( true === $plan['ok'], 'teardown: ownership is proven for a genuine fixture' );

// Foreign clinic slug: the manifest no longer describes a live row.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
foreach ( $ctx['platform']->tables_data['cpms_clinics'] as $index => $row ) {
	if ( (int) $row['id'] === (int) $post['manifest']['ids']['clinic_id'] ) {
		$ctx['platform']->tables_data['cpms_clinics'][ $index ]['slug'] = 'a-real-clinic';
	}
}
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'teardown: a clinic whose slug no longer matches is refused'
);
Cpdd_Test::equals( 2, count( $ctx['platform']->tables_data['cpms_clinics'] ), 'teardown: nothing is deleted when ownership fails' );

// Promoted persona.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['env']->users[ $post['manifest']['ids']['user_ids']['accountant'] ]['roles'] = array( 'administrator' );
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'teardown: a persona promoted to Administrator is refused'
);

// Unknown table with data.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_mystery_table'] = array( array( 'id' => 1 ) );
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'teardown: an unknown table holding data blocks deletion'
);

// Successful cleanup, including activity created after provisioning.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_patients'][] = array(
	'id'        => 55,
	'clinic_id' => (int) $post['manifest']['ids']['clinic_id'],
	'mobile'    => '09001112233',
);
$ctx['platform']->tables_data['cpms_appointments'][] = array(
	'id'         => 55,
	'clinic_id'  => (int) $post['manifest']['ids']['clinic_id'],
	'patient_id' => 55,
);
$ctx['platform']->tables_data['cpms_otp_tokens'][] = array(
	'id'          => 9,
	'clinic_id'   => (int) $post['manifest']['ids']['clinic_id'],
	'mobile'      => $post['manifest']['patient_mobile'],
	'purpose'     => 'login',
	'consumed_at' => null,
	'attempts'    => 0,
	'expires_at'  => gmdate( 'Y-m-d H:i:s', $ctx['env']->now + 60 ),
	'code_hash'   => 'x',
);
$result = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 );
Cpdd_Test::ok( true === $result['ok'], 'teardown: cleanup verified with no remaining artifact' );
Cpdd_Test::ok( array() === $ctx['platform']->tables_data['cpms_patients'], 'teardown: post-provisioning synthetic patients removed' );
Cpdd_Test::ok( array() === $ctx['platform']->tables_data['cpms_appointments'], 'teardown: post-provisioning appointments removed' );
Cpdd_Test::ok( array() === $ctx['platform']->tables_data['cpms_otp_tokens'], 'teardown: demo OTP challenges removed' );
Cpdd_Test::ok( isset( $ctx['env']->users[1] ), 'teardown: the Owner Administrator account is preserved' );
Cpdd_Test::ok( ! isset( $ctx['env']->users[ $post['manifest']['ids']['user_ids']['doctor'] ] ), 'teardown: demo personas removed' );
Cpdd_Test::equals( 1, count( $ctx['platform']->tables_data['cpms_clinics'] ), 'teardown: default clinic preserved' );
Cpdd_Test::equals( 1, count( $ctx['platform']->tables_data['cpms_organizations'] ), 'teardown: default organization preserved' );
Cpdd_Test::equals( 1, count( $ctx['platform']->tables_data['cpms_schema_migrations'] ), 'teardown: baseline schema_migrations preserved' );
Cpdd_Test::equals( 1, count( $ctx['platform']->tables_data['cpms_license_install'] ), 'teardown: baseline license_install preserved' );
Cpdd_Test::equals( 2, count( $ctx['platform']->tables_data['cpms_jobs'] ), 'teardown: baseline recurring jobs preserved' );
Cpdd_Test::equals( 2, count( $ctx['platform']->tables_data['cpms_operational_logs'] ), 'teardown: baseline operational logs preserved and demo logs removed' );
Cpdd_Test::equals( 0, count( $ctx['platform']->tables_data['cpms_invoice_items'] ), 'teardown: child invoice_items removed' );
Cpdd_Test::equals( 0, count( $ctx['platform']->tables_data['cpms_prescription_items'] ), 'teardown: child prescription_items removed' );
Cpdd_Test::equals( 0, count( $ctx['platform']->tables_data['cpms_visit_status_history'] ), 'teardown: child visit_status_history removed' );
Cpdd_Test::equals( 0, count( $ctx['platform']->tables_data['cpms_rate_limits'] ), 'teardown: ephemeral rate_limits removed' );
Cpdd_Test::equals( 0, count( $ctx['platform']->tables_data['cpms_audit_logs'] ), 'teardown: demo audit_logs removed' );
Cpdd_Test::ok( true === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'], 'teardown: preflight passes again after clean teardown' );
Cpdd_Test::ok( null !== $ctx['env']->option_get( Manifest::TOMBSTONE ), 'teardown: tombstone record written' );
Cpdd_Test::ok( false === $ctx['env']->option_get( Manifest::OPTION ), 'teardown: manifest removed after successful cleanup' );
Cpdd_Test::ok( ! str_contains( json_encode( $ctx['env']->option_get( Manifest::TOMBSTONE ) ), 'password' ), 'teardown: tombstone holds no credential material' );

// Foreign child row in cpms_invoice_items (outside fixture invoices) blocks teardown.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_invoice_items'][] = array( 'id' => 99, 'invoice_id' => 999, 'service_id' => 1, 'amount' => 500 );
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'teardown: foreign child row in cpms_invoice_items blocks deletion'
);

// Commercial license_state row blocks both preflight and teardown.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_license_state'][] = array( 'id' => 1, 'license_id' => 'LIC-LIVE' );
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'teardown: active license_state row blocks deletion'
);

// Refusal must also be recorded as a diagnostic, not a silent success.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_mystery_table'] = array( array( 'id' => 1 ) );
$diag = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->diagnose( $post['manifest'], 1 );
Cpdd_Test::ok( false === $diag['ok'] && array() !== $diag['unknown'], 'teardown: diagnostic reports unprovable ownership without deleting' );
Cpdd_Test::ok( 2 === count( $ctx['platform']->tables_data['cpms_clinics'] ), 'teardown: diagnostic is read-only' );

// ---------------------------------------------------------------------------
// 8. S-1 Safe Uninstall (preserve manifest & recovery metadata when active/incomplete)
// ---------------------------------------------------------------------------
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}

// 8a. Uninstall while demo is active must preserve cpdd_manifest and all fixture rows.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
require CPMS_DEMO_DIR . 'uninstall.php';
Cpdd_Test::ok( is_array( $ctx['env']->option_get( Manifest::OPTION, null ) ), 's1: uninstall with active fixture preserves cpdd_manifest' );
Cpdd_Test::ok( is_array( $ctx['env']->option_get( 'cpdd_uninstall_blocked', null ) ), 's1: uninstall with active fixture records cpdd_uninstall_blocked recovery marker' );
Cpdd_Test::equals( 2, count( $ctx['platform']->tables_data['cpms_clinics'] ), 's1: uninstall never auto-deletes clinic or clinical records' );
Cpdd_Test::equals( 7, count( $ctx['env']->users ), 's1: uninstall never auto-deletes WordPress users' );

// Reinstall & recover: Teardown::execute() succeeds and clears cpdd_uninstall_blocked.
$res_after_reinstall = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $ctx['manifest']->load(), 1 );
Cpdd_Test::ok( true === $res_after_reinstall['ok'], 's1: after reinstalling companion plugin, teardown recovers cleanly' );
Cpdd_Test::ok( false === $ctx['env']->option_get( 'cpdd_uninstall_blocked', false ), 's1: clean teardown clears cpdd_uninstall_blocked marker' );

// 8b. Uninstall after clean teardown removes tombstone and leaves zero cpdd_* options.
require CPMS_DEMO_DIR . 'uninstall.php';
Cpdd_Test::ok( false === $ctx['env']->option_get( Manifest::TOMBSTONE, false ), 's1: uninstall after clean teardown removes cpdd_tombstone' );
Cpdd_Test::ok( false === $ctx['env']->option_get( Manifest::OPTION, false ), 's1: uninstall after clean teardown leaves no cpdd_manifest' );

// ---------------------------------------------------------------------------
// 9. S-2 Safe Teardown (fail closed on SQL/post/user error, preserve manifest)
// ---------------------------------------------------------------------------
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->fail_delete_on = 'cpms_visits';
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'SQL_DELETE_FAILED',
	's2: SQL failure during table deletion fails closed with SQL_DELETE_FAILED'
);
$failed_manifest = $ctx['manifest']->load();
Cpdd_Test::ok( is_array( $failed_manifest ), 's2: cpdd_manifest is preserved when teardown fails partway through' );
Cpdd_Test::equals( 'teardown_failed', $failed_manifest['status'] ?? '', 's2: manifest status records teardown_failed' );
Cpdd_Test::equals( 'table:cpms_visits', $failed_manifest['failed_stage'] ?? '', 's2: manifest records exact failed stage' );
Cpdd_Test::ok( false === $ctx['env']->option_get( Manifest::TOMBSTONE, false ), 's2: tombstone is not written when teardown fails' );

// Uninstall during teardown_failed must also preserve manifest!
require CPMS_DEMO_DIR . 'uninstall.php';
Cpdd_Test::ok( is_array( $ctx['manifest']->load() ), 's1/s2: uninstall during teardown_failed preserves cpdd_manifest' );

// Retry teardown after SQL error is resolved: recovers using scope_snapshot and completes cleanly.
$ctx['platform']->fail_delete_on = null;
$retry_res = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $ctx['manifest']->load(), 1 );
Cpdd_Test::ok( true === $retry_res['ok'], 's2: retry after mid-teardown SQL failure completes cleanly' );
Cpdd_Test::ok( null === $ctx['manifest']->load(), 's2: manifest deleted only after retry proves cleanup complete' );

// User deletion failure during teardown preserves manifest and supports retry.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['env']->delete_user_result = false;
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'USER_DELETE_FAILED',
	's2: user deletion failure fails closed with USER_DELETE_FAILED'
);
Cpdd_Test::equals( 'teardown_failed', $ctx['manifest']->load()['status'] ?? '', 's2: manifest preserved on user deletion failure' );
$ctx['env']->delete_user_result = true;
$retry_user_res = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $ctx['manifest']->load(), 1 );
Cpdd_Test::ok( true === $retry_user_res['ok'], 's2: retry after user deletion failure completes cleanly' );

// ---------------------------------------------------------------------------
// 10. S-3 Record Ownership (durable relationships, preflight & teardown foreign rows)
// ---------------------------------------------------------------------------
// 10a. Pre-existing foreign rows before provisioning are caught by Preflight.
foreach ( array(
	'cpms_audit_logs'       => array( 'id' => 1, 'clinic_id' => 1, 'action' => 'PATIENT_CREATE' ),
	'cpms_drug_reference'   => array( 'id' => 1, 'clinic_id' => 1, 'generic_name' => 'Aspirin' ),
	'cpms_rate_limits'      => array( 'window_key' => 'otp-day:09121112233', 'window_id' => 1, 'hits' => 1 ),
	'cpms_jobs'             => array( 'id' => 99, 'type' => 'sms.send', 'status' => 'queued', 'payload_json' => '{"message_id":5}' ),
	'cpms_operational_logs' => array( 'id' => 99, 'level' => 'warning', 'message' => 'LOGIN_FAILED', 'context_json' => '{"username_hash":"login-user:foreign"}' ),
) as $table_name => $foreign_row ) {
	$ctx = cpdd_fixture();
	$ctx['platform']->tables_data[ $table_name ][] = $foreign_row;
	$pf = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
	Cpdd_Test::ok( false === $pf['ok'], 's3 preflight: pre-existing operational row in ' . $table_name . ' blocks provisioning' );
}

// 10b. Post-provisioning foreign rows with id > baseline_max_id block teardown (id > baseline_max_id is NOT ownership proof).
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_audit_logs'][] = array(
	'id'               => 999,
	'clinic_id'        => null,
	'action'           => 'FOREIGN_NULL_CLINIC_AUDIT',
	'resource_type'    => 'organization',
	'resource_id'      => 1,
	'actor_wp_user_id' => 1,
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	's3 teardown: foreign audit_log row with id > baseline_max_id blocks teardown'
);

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_jobs'][] = array(
	'id'           => 999,
	'type'         => 'sms.send',
	'status'       => 'queued',
	'payload_json' => '{"message_id":99999}',
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	's3 teardown: foreign sms.send job with id > baseline_max_id blocks teardown'
);

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_operational_logs'][] = array(
	'id'           => 999,
	'level'        => 'info',
	'message'      => 'BOOKING_CREATED',
	'context_json' => '{"appointment_id":99999,"patient_id":88888}',
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	's3 teardown: foreign operational_log row with id > baseline_max_id blocks teardown'
);

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_rate_limits'][] = array(
	'window_key' => 'otp-day:09129998877',
	'window_id'  => 20000,
	'hits'       => 1,
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	's3 teardown: foreign rate_limit row blocks teardown (never uses window_id >= 0)'
);

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_drug_reference'][] = array(
	'id'           => 50,
	'clinic_id'    => 1,
	'generic_name' => 'Foreign-Clinic-Drug',
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	's3 teardown: foreign drug_reference row in Clinic 1 blocks teardown'
);

// 10c. Legitimate baseline recurring job ticked after provisioning (high id, empty payload) is preserved, while owned rows in audit/jobs/oplogs/rate_limits/drug_reference are removed.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$cid  = (int) $post['manifest']['ids']['clinic_id'];
$oid  = (int) $post['manifest']['ids']['organization_id'];
$ctx['platform']->tables_data['cpms_jobs'][] = array(
	'id'           => 500,
	'type'         => 'cleanup.otp',
	'status'       => 'success',
	'payload_json' => '[]',
);
$ctx['platform']->tables_data['cpms_sms_messages'][] = array(
	'id'        => 42,
	'clinic_id' => $cid,
	'recipient' => $post['manifest']['patient_mobile'],
);
$ctx['platform']->tables_data['cpms_jobs'][] = array(
	'id'           => 501,
	'type'         => 'sms.send',
	'status'       => 'queued',
	'payload_json' => '{"message_id":42}',
);
$ctx['platform']->tables_data['cpms_audit_logs'][] = array(
	'id'            => 502,
	'clinic_id'     => null,
	'action'        => 'ORGANIZATION_OWNER_BOOTSTRAPPED',
	'resource_type' => 'organization',
	'resource_id'   => $oid,
);
$ctx['platform']->tables_data['cpms_drug_reference'][] = array(
	'id'           => 10,
	'clinic_id'    => $cid,
	'generic_name' => 'SYNTHETIC-DRUG',
);
$s3_clean = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 );
Cpdd_Test::ok( true === $s3_clean['ok'], 's3 teardown: owned rows across audit_logs, jobs, oplogs, rate_limits, drug_reference are cleanly removed' );
Cpdd_Test::equals( 3, count( $ctx['platform']->tables_data['cpms_jobs'] ), 's3 teardown: new recurring system job row with id=500 is preserved as baseline' );
Cpdd_Test::equals( 0, count( $ctx['platform']->tables_data['cpms_drug_reference'] ), 's3 teardown: fixture drug_reference row removed' );

// ---------------------------------------------------------------------------
// 11. S-4 Partial Operations Recovery (fault injection at each provisioning stage)
// ---------------------------------------------------------------------------
foreach ( array(
	'provision:users_partial',
	'provision:users',
	'provision:organization',
	'provision:before_clinic',
	'provision:clinical',
	'provision:booking-page',
) as $fault_point ) {
	$ctx = cpdd_fixture();
	$ctx['env']->set_fault_injector(
		static function ( string $point ) use ( $fault_point ): void {
			if ( $point === $fault_point ) {
				throw new DemoException( 'INJECTED_FAULT', 'خطای تزریق‌شده در ' . $point );
			}
		}
	);
	Cpdd_Test::refuses(
		static fn () => cpdd_provision( $ctx ),
		'INJECTED_FAULT',
		's4: provisioning stops at injected fault ' . $fault_point
	);
	$ctx['env']->set_fault_injector( null );
	$partial = $ctx['manifest']->load();
	Cpdd_Test::ok( is_array( $partial ) && 'failed' === ( $partial['status'] ?? '' ), 's4: partial manifest preserved with failed status at ' . $fault_point );
	$recovered = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $partial, 1 );
	Cpdd_Test::ok( true === $recovered['ok'], 's4: teardown safely recovers partial fixture from ' . $fault_point );
	Cpdd_Test::ok( true === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'], 's4: site returns to clean preflight state after recovering ' . $fault_point );
}

// 11b. Previously deleted fixture user, booking page, and clinic record do not block recovery.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
unset( $ctx['env']->users[ $post['manifest']['ids']['user_ids']['doctor'] ] );
unset( $ctx['env']->posts[ $post['manifest']['ids']['booking_page_id'] ] );
$ctx['platform']->tables_data['cpms_clinics'] = array(
	array( 'id' => 1, 'organization_id' => 1, 'slug' => 'default', 'status' => 'active' ),
);
$s4_missing = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 );
Cpdd_Test::ok( true === $s4_missing['ok'], 's4: previously deleted fixture user, page, and clinic record do not block teardown recovery' );
Cpdd_Test::equals( 1, count( $ctx['env']->users ), 's4: remaining fixture users removed while Admin preserved' );

// ---------------------------------------------------------------------------
// 12. S-5 Concurrency Guard (contention rejection & lock release after failure)
// ---------------------------------------------------------------------------
$ctx = cpdd_fixture();
$ctx['env']->simulate_lock_contention = true;
Cpdd_Test::refuses(
	static fn () => cpdd_provision( $ctx ),
	'CONCURRENT_OPERATION',
	's5: concurrent provisioning is rejected when lock is held'
);
Cpdd_Test::equals( 1, count( $ctx['platform']->tables_data['cpms_organizations'] ), 's5: rejected concurrent provisioning creates zero untracked fixtures' );
$ctx['env']->simulate_lock_contention = false;

// Re-entrant mutation inside an active lock is rejected with CONCURRENT_OPERATION.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['env']->set_fault_injector(
	static function ( string $point ) use ( $ctx, $post ): void {
		if ( 'delete_where:cpms_visits' === $point ) {
			( new Credentials( $ctx['env'] ) )->rotate( $post['manifest'] );
		}
	}
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'CONCURRENT_OPERATION',
	's5: overlapping password rotation during active teardown is rejected by concurrency guard'
);
$ctx['env']->set_fault_injector( null );
$s5_recover = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $ctx['manifest']->load(), 1 );
Cpdd_Test::ok( true === $s5_recover['ok'], 's5: lock is released in finally block after contention failure so recovery succeeds' );

// ---------------------------------------------------------------------------
// 13. S-6 Password Rotation & Admin UI One-Time Credential Rendering
// ---------------------------------------------------------------------------
$ctx = cpdd_fixture();
Admin::reset_state();
Admin::set_test_services( $ctx['env'], $ctx['platform'] );
$req_helper = new Request( $ctx['env'], $ctx['manifest'] );

// Simulate browser POST for ACTION_PROVISION.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array(
	Admin::ACTION  => Request::ACTION_PROVISION,
	'cpdd_nonce'   => $ctx['env']->nonce_create( $req_helper->nonce_action( Request::ACTION_PROVISION ) ),
	'cpdd_site'    => $req_helper->site_fingerprint_field(),
	'cpdd_confirm' => $req_helper->phrase( Request::ACTION_PROVISION ),
);
Admin::dispatch();
ob_start();
Admin::render();
$html_provision = (string) ob_get_clean();
$active_manifest = $ctx['manifest']->load();
$fx_id           = (string) $active_manifest['fixture_id'];
Cpdd_Test::ok( str_contains( $html_provision, 'data-cpdd-secret="credentials"' ), 's6: provisioning response renders one-time credentials block' );
foreach ( Credentials::PERSONA_ORDER as $persona_key ) {
	Cpdd_Test::ok(
		str_contains( $html_provision, 'cpdd-' . $persona_key . '-' . $fx_id ),
		's6: provisioning response renders complete username for ' . $persona_key
	);
}

// Simulate browser POST for ACTION_ROTATE (where self::$provisioned is null!).
Admin::reset_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array(
	Admin::ACTION  => Request::ACTION_ROTATE,
	'cpdd_nonce'   => $ctx['env']->nonce_create( $req_helper->nonce_action( Request::ACTION_ROTATE ) ),
	'cpdd_site'    => $req_helper->site_fingerprint_field(),
	'cpdd_confirm' => $req_helper->phrase( Request::ACTION_ROTATE ),
);
Admin::dispatch();
ob_start();
Admin::render();
$html_rotate = (string) ob_get_clean();
Cpdd_Test::ok( str_contains( $html_rotate, 'data-cpdd-secret="credentials"' ), 's6: rotation response renders one-time credentials block' );
foreach ( Credentials::PERSONA_ORDER as $persona_key ) {
	$expected_login = 'cpdd-' . $persona_key . '-' . $fx_id;
	Cpdd_Test::ok(
		str_contains( $html_rotate, 'data-cpdd-login="' . $expected_login . '"' ),
		's6: rotation response renders complete username with fixture_id for ' . $persona_key
	);
}

// Simulate subsequent GET reload: credentials block must not appear!
Admin::reset_state();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST                     = array();
ob_start();
Admin::render();
$html_get = (string) ob_get_clean();
Cpdd_Test::ok( ! str_contains( $html_get, 'data-cpdd-secret="credentials"' ), 's6: subsequent GET reload never renders plaintext credentials' );
foreach ( $ctx['env']->passwords as $stored_pass ) {
	Cpdd_Test::ok( ! str_contains( $html_get, (string) $stored_pass ), 's6: subsequent GET reload contains zero password values' );
}

// Simulate browser POST for ACTION_TEARDOWN: must render data-cpdd-teardown="clean" even though manifest is now deleted.
$ctx['platform']->tables_data['cpms_rate_limits'][] = array(
	'window_key'   => 'otp-ip:127.0.0.1',
	'window_start' => time(),
	'window_sec'   => 3600,
	'counter'      => 1,
);
Admin::reset_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array(
	Admin::ACTION  => Request::ACTION_TEARDOWN,
	'cpdd_nonce'   => $ctx['env']->nonce_create( $req_helper->nonce_action( Request::ACTION_TEARDOWN ) ),
	'cpdd_site'    => $req_helper->site_fingerprint_field(),
	'cpdd_confirm' => $req_helper->phrase( Request::ACTION_TEARDOWN ),
);
Admin::dispatch();
ob_start();
Admin::render();
$html_teardown = (string) ob_get_clean();
Cpdd_Test::ok( str_contains( $html_teardown, 'data-cpdd-teardown="clean"' ), 's6: teardown response renders data-cpdd-teardown="clean" after manifest deletion' );
Cpdd_Test::ok( str_contains( $html_teardown, 'data-cpdd-tombstone="clean"' ), 's6: teardown response renders data-cpdd-tombstone="clean"' );
Cpdd_Test::ok( str_contains( $html_teardown, 'data-cpdd-preflight="pass"' ), 's6: teardown response renders data-cpdd-preflight="pass"' );

// Simulate browser POST for ACTION_DISMISS followed by ACTION_PROVISION with preserved shared-IP rate limits.
Admin::reset_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array(
	Admin::ACTION  => Request::ACTION_DISMISS,
	'cpdd_nonce'   => $ctx['env']->nonce_create( $req_helper->nonce_action( Request::ACTION_DISMISS ) ),
	'cpdd_site'    => $req_helper->site_fingerprint_field(),
	'cpdd_confirm' => $req_helper->phrase( Request::ACTION_DISMISS ),
);
Admin::dispatch();
ob_start();
Admin::render();
$html_dismiss = (string) ob_get_clean();
Cpdd_Test::ok( ! str_contains( $html_dismiss, 'data-cpdd-tombstone=' ), 's6: dismiss response removes tombstone card' );
Cpdd_Test::ok( str_contains( $html_dismiss, 'data-cpdd-preflight="pass"' ), 's6: preflight remains pass after dismissing tombstone with preserved shared-IP rate limits' );

Admin::reset_state();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array(
	Admin::ACTION  => Request::ACTION_PROVISION,
	'cpdd_nonce'   => $ctx['env']->nonce_create( $req_helper->nonce_action( Request::ACTION_PROVISION ) ),
	'cpdd_site'    => $req_helper->site_fingerprint_field(),
	'cpdd_confirm' => $req_helper->phrase( Request::ACTION_PROVISION ),
);
Admin::dispatch();
ob_start();
Admin::render();
$html_reprovision = (string) ob_get_clean();
Cpdd_Test::ok( str_contains( $html_reprovision, 'data-cpdd-secret="credentials"' ), 's6: reprovisioning after ACTION_DISMISS renders new one-time credentials block' );
Admin::set_test_services( null, null );

// ---------------------------------------------------------------------------
// 14. Regression: request_id isolation, shared-IP rate-limits, unscoped OTP, & reprovisioning
// ---------------------------------------------------------------------------

// 14a. Mixed demo/foreign activity sharing the same PHP request_id in cpms_operational_logs must block teardown.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$cid  = (int) $post['manifest']['ids']['clinic_id'];
$ctx['platform']->tables_data['cpms_audit_logs'][] = array(
	'id'         => 700,
	'clinic_id'  => $cid,
	'action'     => 'DEMO_ACTION',
	'request_id' => 'shared-req-id-001',
);
$ctx['platform']->tables_data['cpms_operational_logs'][] = array(
	'id'           => 701,
	'level'        => 'info',
	'message'      => 'PATIENT_CREATED',
	'request_id'   => 'shared-req-id-001',
	'context_json' => '{"clinic_id":1,"patient_id":9999}',
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'reg-14a: foreign operational_log row sharing request_id with demo audit row blocks teardown'
);
Cpdd_Test::equals( 4, count( $ctx['platform']->tables_data['cpms_operational_logs'] ), 'reg-14a: foreign operational_log row sharing request_id is never deleted' );

// 14b. Correct ip_hash / username_hash and LOGIN_FAILED / LOGIN_THROTTLED attribution.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$fx   = (string) $post['manifest']['fixture_id'];
$doc_login = 'cpdd-doctor-' . $fx;
$doc_hash  = $ctx['env']->username_hash( $doc_login );
Cpdd_Test::equals( 32, strlen( $doc_hash ), 'reg-14b: username_hash is 32-char SHA-256 hex without login-user: prefix' );
Cpdd_Test::equals(
	hash_hmac( 'sha256', '127.0.0.1', 'cpms-dev-pepper-change-me' ),
	$ctx['env']->ip_hash( '127.0.0.1' ),
	'reg-14b: ip_hash matches AuditLogger::ipHash() pepper'
);
$ctx['platform']->tables_data['cpms_operational_logs'][] = array(
	'id'           => 710,
	'level'        => 'warning',
	'message'      => 'LOGIN_FAILED',
	'context_json' => (string) json_encode( array( 'username_hash' => $doc_hash, 'ip' => '127.0.0.1' ) ),
);
$ctx['platform']->tables_data['cpms_operational_logs'][] = array(
	'id'           => 711,
	'level'        => 'warning',
	'message'      => 'LOGIN_THROTTLED',
	'context_json' => (string) json_encode( array( 'username_hash' => $doc_hash, 'ip' => '127.0.0.1' ) ),
);
$ctx['platform']->tables_data['cpms_rate_limits'][] = array(
	'window_key' => 'login-user:' . $doc_hash,
	'window_id'  => 20000,
	'hits'       => 2,
);
$res_14b = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 );
Cpdd_Test::ok( true === $res_14b['ok'], 'reg-14b: synthetic persona LOGIN_FAILED/LOGIN_THROTTLED oplogs and login-user rate_limit are attributed and removed' );

// Foreign username_hash in LOGIN_FAILED blocks teardown.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_operational_logs'][] = array(
	'id'           => 712,
	'level'        => 'warning',
	'message'      => 'LOGIN_FAILED',
	'context_json' => (string) json_encode( array( 'username_hash' => $ctx['env']->username_hash( 'real-doctor' ), 'ip' => '127.0.0.1' ) ),
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'reg-14b: foreign username_hash in LOGIN_FAILED operational_log blocks teardown'
);

// 14c. Shared-IP rate-limit rows (otp-ip:*, otp-verify-ip:*, login-ip:*) are preserved across teardown and allowed on reprovisioning.
$ctx = cpdd_fixture();
$ctx['platform']->tables_data['cpms_rate_limits'][] = array(
	'window_key' => 'otp-ip:127.0.0.1',
	'window_id'  => 20000,
	'hits'       => 1,
);
Cpdd_Test::ok(
	false === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'],
	'reg-14c: shared-IP rate-limit row on a brand-new site before any demo teardown blocks initial preflight'
);

$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_rate_limits'][] = array(
	'window_key' => 'otp-ip:127.0.0.1',
	'window_id'  => 20000,
	'hits'       => 3,
);
$ctx['platform']->tables_data['cpms_rate_limits'][] = array(
	'window_key' => 'otp-verify-ip:127.0.0.1',
	'window_id'  => 20000,
	'hits'       => 2,
);
$ctx['platform']->tables_data['cpms_rate_limits'][] = array(
	'window_key' => 'login-ip:127.0.0.1',
	'window_id'  => 20000,
	'hits'       => 1,
);
$td_14c = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 );
Cpdd_Test::ok( true === $td_14c['ok'], 'reg-14c: teardown succeeds while preserving globally shared IP rate-limit counters' );
Cpdd_Test::equals( 3, count( $ctx['platform']->tables_data['cpms_rate_limits'] ), 'reg-14c: all 3 shared-IP rate-limit counters are preserved and entity counters removed' );
Cpdd_Test::equals( 3, $td_14c['tombstone']['preserved_shared_ip_rate_limits'] ?? -1, 'reg-14c: tombstone records preserved_shared_ip_rate_limits count' );

// Dismiss tombstone and reprovision on the same disposable site, then run second teardown.
$ctx['manifest']->clear_tombstone();
Cpdd_Test::ok(
	true === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'],
	'reg-14c: preflight passes for reprovisioning after verified teardown even when shared-IP rate-limit counters are preserved'
);
$post_second = cpdd_provision( $ctx );
Cpdd_Test::ok(
	(string) $post_second['manifest']['fixture_id'] !== (string) $post['manifest']['fixture_id'],
	'reg-14c: reprovisioning generates a distinct fixture_id'
);
$td_second = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post_second['manifest'], 1 );
Cpdd_Test::ok( true === $td_second['ok'], 'reg-14c: second teardown completes cleanly' );
Cpdd_Test::equals( 3, count( $ctx['platform']->tables_data['cpms_rate_limits'] ), 'reg-14c: shared-IP rate-limit counters remain preserved after second teardown' );

// Verify tombstone HMAC and site-fingerprint enforcement before trusting tombstone in preflight.
$valid_tombstone = $ctx['env']->option_get( Manifest::TOMBSTONE );
Cpdd_Test::ok( is_array( $ctx['manifest']->tombstone() ), 'tombstone: valid sealed tombstone verified by Manifest::tombstone()' );

$tampered_tombstone        = $valid_tombstone;
$tampered_tombstone['mac'] = str_repeat( '0', 64 );
$ctx['env']->option_set( Manifest::TOMBSTONE, $tampered_tombstone );
Cpdd_Test::equals( null, $ctx['manifest']->tombstone(), 'tombstone: invalid HMAC rejected by Manifest::tombstone()' );
Cpdd_Test::ok(
	in_array( 'cpms_data', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'tombstone: invalid HMAC tombstone cannot relax disposable-site preflight when shared-IP rate limits exist'
);

$unsealed_tombstone = $valid_tombstone;
unset( $unsealed_tombstone['mac'] );
$ctx['env']->option_set( Manifest::TOMBSTONE, $unsealed_tombstone );
Cpdd_Test::equals( null, $ctx['manifest']->tombstone(), 'tombstone: missing HMAC rejected by Manifest::tombstone()' );
Cpdd_Test::ok(
	in_array( 'cpms_data', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'tombstone: unsealed tombstone cannot relax disposable-site preflight'
);

$ctx['env']->option_set( Manifest::TOMBSTONE, $valid_tombstone );
$orig_home        = $ctx['env']->home;
$ctx['env']->home = 'https://other-site.example.test/';
Cpdd_Test::equals( null, $ctx['manifest']->tombstone(), 'tombstone: cross-site tombstone rejected by Manifest::tombstone()' );
Cpdd_Test::ok(
	in_array( 'cpms_data', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'tombstone: cross-site tombstone cannot relax disposable-site preflight'
);
$ctx['env']->home = $orig_home;
Cpdd_Test::ok(
	true === ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['ok'],
	'tombstone: restoring matching site identity restores verified tombstone preflight pass'
);
$ctx['manifest']->clear_tombstone();
$ctx['env']->home = 'https://other-site.example.test/';
Cpdd_Test::ok(
	in_array( 'cpms_data', ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run()['blocking'], true ),
	'tombstone: cross-site dismissed tombstone marker cannot relax disposable-site preflight'
);
$ctx['env']->home = $orig_home;

// 14d. OTP audit ownership & adversarial 4-digit suffix collision fail-closed checks.
// Clinic-scoped OTP challenge and audit row for synthetic 0900 mobile without suffix collision is attributed and removed.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$cid  = (int) $post['manifest']['ids']['clinic_id'];
$mob  = (string) $post['manifest']['patient_mobile'];
$mask = '***' . substr( $mob, -4 );
$ctx['platform']->tables_data['cpms_otp_tokens'][] = array(
	'id'          => 80,
	'clinic_id'   => $cid,
	'mobile'      => $mob,
	'purpose'     => 'login',
	'consumed_at' => null,
	'attempts'    => 0,
	'expires_at'  => gmdate( 'Y-m-d H:i:s', $ctx['env']->now + 60 ),
	'code_hash'   => 'x',
);
$ctx['platform']->tables_data['cpms_audit_logs'][] = array(
	'id'               => 800,
	'clinic_id'        => $cid,
	'actor_wp_user_id' => null,
	'action'           => 'OTP_REQUEST',
	'resource_type'    => 'otp',
	'resource_id'      => null,
	'after_json'       => (string) json_encode( array( 'mobile' => $mask ) ),
);
$td_14d = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 );
Cpdd_Test::ok( true === $td_14d['ok'], 'reg-14d: clinic-scoped OTP audit row for synthetic 0900 mobile without suffix collision is attributed and removed' );
Cpdd_Test::equals( 0, count( $ctx['platform']->tables_data['cpms_audit_logs'] ), 'reg-14d: clinic-scoped synthetic OTP audit row deleted' );

// Unscoped OTP audit row for a foreign mobile blocks teardown.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_audit_logs'][] = array(
	'id'               => 801,
	'clinic_id'        => null,
	'actor_wp_user_id' => null,
	'action'           => 'OTP_REQUEST',
	'resource_type'    => 'otp',
	'resource_id'      => null,
	'after_json'       => '{"mobile":"***9876"}',
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'reg-14d: unscoped OTP audit row for foreign mobile blocks teardown'
);
Cpdd_Test::equals( 2, count( $ctx['platform']->tables_data['cpms_audit_logs'] ), 'reg-14d: foreign unscoped OTP audit row is never deleted' );

// Adversarial unscoped OTP audit row with COLLIDING 4-digit suffix (matching ***XXXX of synthetic 0900 mobile) must fail closed and block teardown.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$cid  = (int) $post['manifest']['ids']['clinic_id'];
$mob  = (string) $post['manifest']['patient_mobile'];
$mask = '***' . substr( $mob, -4 );
$ctx['platform']->tables_data['cpms_otp_tokens'][] = array(
	'id'          => 81,
	'clinic_id'   => $cid,
	'mobile'      => $mob,
	'purpose'     => 'login',
	'consumed_at' => null,
	'attempts'    => 0,
	'expires_at'  => gmdate( 'Y-m-d H:i:s', $ctx['env']->now + 60 ),
	'code_hash'   => 'x',
);
$ctx['platform']->tables_data['cpms_audit_logs'][] = array(
	'id'               => 802,
	'clinic_id'        => null,
	'actor_wp_user_id' => null,
	'action'           => 'OTP_VERIFY_FAIL',
	'resource_type'    => 'otp',
	'resource_id'      => null,
	'after_json'       => (string) json_encode( array( 'mobile' => $mask ) ),
);
Cpdd_Test::refuses(
	static fn () => ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->execute( $post['manifest'], 1 ),
	'TEARDOWN_BLOCKED',
	'reg-14d: adversarial unscoped OTP audit row with colliding 4-digit suffix fails closed and blocks teardown'
);
Cpdd_Test::equals( 2, count( $ctx['platform']->tables_data['cpms_audit_logs'] ), 'reg-14d: colliding unscoped OTP audit row is never deleted' );

// Adversarial clinic-scoped OTP audit row when a foreign mobile collides on the 4-digit suffix in cpms_patients fails closed and blocks teardown.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$cid  = (int) $post['manifest']['ids']['clinic_id'];
$mob  = (string) $post['manifest']['patient_mobile'];
$mask = '***' . substr( $mob, -4 );
$colliding_foreign_mobile = '0912000' . substr( $mob, -4 );
$ctx['platform']->tables_data['cpms_patients'][] = array(
	'id'        => 99,
	'clinic_id' => 1,
	'mobile'    => $colliding_foreign_mobile,
	'name'      => 'Foreign Colliding Patient',
);
$ctx['platform']->tables_data['cpms_audit_logs'][] = array(
	'id'               => 803,
	'clinic_id'        => $cid,
	'actor_wp_user_id' => null,
	'action'           => 'OTP_REQUEST',
	'resource_type'    => 'otp',
	'resource_id'      => null,
	'after_json'       => (string) json_encode( array( 'mobile' => $mask ) ),
);
Cpdd_Test::ok(
	false === $ctx['platform']->otp_suffix_unambiguous( $cid, $mob ),
	'reg-14d: otp_suffix_unambiguous returns false when foreign mobile shares 4-digit suffix'
);
$verify_colliding = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->verify( $post['manifest'] );
Cpdd_Test::ok( false === $verify_colliding['ok'], 'reg-14d: 4-digit suffix collision with foreign mobile blocks teardown' );
$audit_plan_entry = null;
foreach ( $verify_colliding['plan'] as $entry ) {
	if ( 'cpms_audit_logs' === $entry['table'] ) {
		$audit_plan_entry = $entry;
	}
}
Cpdd_Test::ok(
	is_array( $audit_plan_entry ) && (int) ( $audit_plan_entry['foreign_rows'] ?? 0 ) >= 1,
	'reg-14d: suffix collision causes clinic-scoped OTP audit row to be flagged as ambiguous/foreign rather than owned'
);

exit( Cpdd_Test::summary() );
