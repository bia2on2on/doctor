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

use CpmsDemo\Credentials;
use CpmsDemo\Env;
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
$ctx['env']->plugin_list[] = 'woocommerce/woocommerce.php';
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'plugins', $state['blocking'], true ), 'preflight: another active plugin blocks provisioning' );

$ctx = cpdd_fixture();
$ctx['env']->multisite = true;
$state = ( new Preflight( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->run();
Cpdd_Test::ok( in_array( 'single_site', $state['blocking'], true ), 'preflight: multisite blocks provisioning' );

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
Cpdd_Test::ok( null !== $ctx['env']->option_get( Manifest::TOMBSTONE ), 'teardown: tombstone record written' );
Cpdd_Test::ok( false === $ctx['env']->option_get( Manifest::OPTION ), 'teardown: manifest removed after successful cleanup' );
Cpdd_Test::ok( ! str_contains( json_encode( $ctx['env']->option_get( Manifest::TOMBSTONE ) ), 'password' ), 'teardown: tombstone holds no credential material' );

// Refusal must also be recorded as a diagnostic, not a silent success.
$ctx  = cpdd_fixture();
$post = cpdd_provision( $ctx );
$ctx['platform']->tables_data['cpms_mystery_table'] = array( array( 'id' => 1 ) );
$diag = ( new Teardown( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->diagnose( $post['manifest'], 1 );
Cpdd_Test::ok( false === $diag['ok'] && array() !== $diag['unknown'], 'teardown: diagnostic reports unprovable ownership without deleting' );
Cpdd_Test::ok( 2 === count( $ctx['platform']->tables_data['cpms_clinics'] ), 'teardown: diagnostic is read-only' );

exit( Cpdd_Test::summary() );
